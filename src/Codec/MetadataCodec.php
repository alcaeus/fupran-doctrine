<?php

declare(strict_types=1);

namespace App\Codec;

use BackedEnum;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataFactoryInterface;
use Doctrine\ODM\MongoDB\Mapping\MappingException;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\Types\Type;
use Doctrine\ODM\MongoDB\Utility\CollectionHelper;
use InvalidArgumentException;
use MongoDB\BSON\Document;
use MongoDB\Codec\DecodeIfSupported;
use MongoDB\Codec\DocumentCodec;
use MongoDB\Codec\EncodeIfSupported;
use MongoDB\Exception\UnsupportedValueException;
use UnexpectedValueException;

use function array_search;
use function array_values;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * This class shamelessly duplicates a lot of logic from Doctrine's PersistenceBuilder class. It will have to be rewritten
 * to be optimised for the specific codec use cases.
 *
 * Doctrine\ODM\MongoDB\Mapping\ClassMetadata declares its field-mapping shape via
 * a `@phpstan-type FieldMapping` alias on its own docblock, but PHPStan cannot
 * resolve that alias via @phpstan-import-type (reproducible with no application
 * code involved, using only doctrine/mongodb-odm + phpstan/phpstan-doctrine).
 * The shape below is copied from that upstream docblock so mapping arrays here
 * stay precisely typed instead of collapsing to `array<string, mixed>`.
 *
 * @phpstan-type FieldMapping array{
 *      type: string,
 *      fieldName: string,
 *      name: string,
 *      isCascadeRemove: bool,
 *      isCascadePersist: bool,
 *      isCascadeRefresh: bool,
 *      isCascadeMerge: bool,
 *      isCascadeDetach: bool,
 *      isOwningSide: bool,
 *      isInverseSide: bool,
 *      strategy?: string,
 *      association?: int,
 *      id?: bool,
 *      collectionClass?: class-string,
 *      cascade?: list<string>|string,
 *      embedded?: bool,
 *      orphanRemoval?: bool,
 *      options?: array<string, mixed>,
 *      nullable?: bool,
 *      reference?: bool,
 *      storeAs?: string,
 *      targetDocument?: class-string|null,
 *      mappedBy?: string|null,
 *      inversedBy?: string|null,
 *      discriminatorField?: string,
 *      defaultDiscriminatorValue?: string,
 *      discriminatorMap?: array<string, class-string>,
 *      repositoryMethod?: string|null,
 *      sort?: array<string, string|int>,
 *      limit?: int|null,
 *      skip?: int|null,
 *      version?: bool,
 *      lock?: bool,
 *      notSaved?: bool,
 *      inherited?: string,
 *      declared?: class-string,
 *      prime?: list<string>,
 *      sparse?: bool,
 *      unique?: bool,
 *      index?: bool,
 *      criteria?: array<string, mixed>,
 *      alsoLoadFields?: list<string>,
 *      enumType?: class-string<BackedEnum>,
 *      storeEmptyArray?: bool,
 * }
 * @template-implements DocumentCodec<object>
 */
final readonly class MetadataCodec implements DocumentCodec
{
    /** @use DecodeIfSupported<mixed, object> */
    use DecodeIfSupported;
    /** @use EncodeIfSupported<mixed, object> */
    use EncodeIfSupported;

    /** @param ClassMetadata<object> $classMetadata */
    private function __construct(
        private string $className,
        private DocumentManager $documentManager,
        private ClassMetadataFactoryInterface $classMetadataFactory,
        private ClassMetadata $classMetadata,
    ) {
    }

    public static function create(DocumentManager $documentManager, string $className): self
    {
        $classMetadataFactory = $documentManager->getMetadataFactory();
        $classMetadata = $classMetadataFactory->getMetadataFor($className);

        return new self($className, $documentManager, $classMetadataFactory, $classMetadata);
    }

    public function canDecode(mixed $value): bool
    {
        return false;
    }

    public function canEncode(mixed $value): bool
    {
        return $value instanceof $this->className;
    }

    public function decode(mixed $value): object
    {
        throw UnsupportedValueException::invalidDecodableValue($value);
    }

    public function encode(mixed $value): Document
    {
        if (! $this->canEncode($value)) {
            throw UnsupportedValueException::invalidEncodableValue($value);
        }

        return Document::fromPHP($this->getDocumentData($value));
    }

    /** @return array<string, mixed> */
    private function getDocumentData(object $value): array
    {
        $insertData = [];
        foreach ($this->classMetadata->fieldMappings as $rawMapping) {
            // See the class docblock: PHPStan cannot resolve ClassMetadata's own
            // FieldMapping alias for its $fieldMappings property. FieldMapping is
            // a PHPStan-only array-shape alias, not a real class, so it cannot be
            // asserted with instanceof.
            // phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
            /** @var FieldMapping $mapping */
            $mapping = $rawMapping;

            $new = $this->classMetadata->getFieldValue($value, $mapping['fieldName']);

            if ($new === null) {
                if ($mapping['nullable'] ?? false) {
                    $insertData[$mapping['name']] = null;
                }

                continue;
            }

            if (! isset($mapping['association'])) {
                $insertData[$mapping['name']] = Type::getType($mapping['type'])->convertToDatabaseValue($new);
            } elseif ($mapping['association'] === ClassMetadata::REFERENCE_ONE) {
                if (! is_object($new)) {
                    throw new UnexpectedValueException('Expected a REFERENCE_ONE field value to be an object.');
                }

                $insertData[$mapping['name']] = $this->prepareReferencedDocumentValue($mapping, $new);
            } elseif ($mapping['association'] === ClassMetadata::EMBED_ONE) {
                if (! is_object($new)) {
                    throw new UnexpectedValueException('Expected an EMBED_ONE field value to be an object.');
                }

                $insertData[$mapping['name']] = $this->prepareEmbeddedDocumentValue($mapping, $new);
            } elseif ($mapping['type'] === ClassMetadata::MANY && ! $mapping['isInverseSide']) {
                if (! $new instanceof PersistentCollectionInterface) {
                    throw new UnexpectedValueException('Expected a MANY field value to be a PersistentCollectionInterface.');
                }

                if (! $new->isEmpty() || ($mapping['storeEmptyArray'] ?? false)) {
                    $insertData[$mapping['name']] = $this->prepareAssociatedCollectionValue($new, true);
                }
            }
        }

        // add discriminator if the class has one
        if (isset($this->classMetadata->discriminatorField)) {
            $discriminatorValue = $this->classMetadata->discriminatorValue;

            if ($discriminatorValue === null) {
                if (! empty($this->classMetadata->discriminatorMap)) {
                    throw MappingException::unlistedClassInDiscriminatorMap($this->classMetadata->name);
                }

                $discriminatorValue = $this->classMetadata->name;
            }

            $insertData[$this->classMetadata->discriminatorField] = $discriminatorValue;
        }

        return $insertData;
    }

    /**
     * Returns the reference representation to be stored in MongoDB.
     *
     * If the document does not have an identifier and the mapping calls for a
     * simple reference, null may be returned.
     *
     * @param FieldMapping $referenceMapping
     *
     * @return array<string, mixed>|null
     */
    private function prepareReferencedDocumentValue(array $referenceMapping, object $document): ?array
    {
        $reference = $this->documentManager->createReference($document, $referenceMapping);
        if ($reference === null) {
            return null;
        }

        if (! is_array($reference)) {
            throw new UnexpectedValueException('Expected createReference() to return an array or null.');
        }

        $stringKeyedReference = [];
        foreach ($reference as $key => $referenceValue) {
            if (! is_string($key)) {
                throw new UnexpectedValueException('Expected createReference() to return a string-keyed array.');
            }

            $stringKeyedReference[$key] = $referenceValue;
        }

        return $stringKeyedReference;
    }

    /**
     * @param FieldMapping $embeddedMapping
     *
     * @return array<string, mixed>|object
     */
    private function prepareEmbeddedDocumentValue(array $embeddedMapping, object $embeddedDocument, bool $includeNestedCollections = false): array|object
    {
        $embeddedDocumentValue = [];
        $class = $this->classMetadataFactory->getMetadataFor($embeddedDocument::class);

        foreach ($class->fieldMappings as $rawMapping) {
            // See the class docblock: PHPStan cannot resolve ClassMetadata's own
            // FieldMapping alias for its $fieldMappings property. FieldMapping is
            // a PHPStan-only array-shape alias, not a real class, so it cannot be
            // asserted with instanceof.
            // phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
            /** @var FieldMapping $mapping */
            $mapping = $rawMapping;

            // Skip notSaved fields
            if (! empty($mapping['notSaved'])) {
                continue;
            }

            // Inline ClassMetadata::getFieldValue()
            $reflField = $class->reflFields[$mapping['fieldName']] ?? null;
            if ($reflField === null) {
                throw new UnexpectedValueException(sprintf('No reflection property found for field "%s".', $mapping['fieldName']));
            }

            $rawValue = $reflField->getValue($embeddedDocument);

            $value = null;

            if ($rawValue !== null) {
                switch ($mapping['association'] ?? null) {
                    // @Field, @String, @Date, etc.
                    case null:
                        $value = Type::getType($mapping['type'])->convertToDatabaseValue($rawValue);
                        break;

                    case ClassMetadata::EMBED_ONE:
                    case ClassMetadata::REFERENCE_ONE:
                        if (! is_object($rawValue)) {
                            throw new UnexpectedValueException('Expected an EMBED_ONE/REFERENCE_ONE field value to be an object.');
                        }

                        // Nested collections should only be included for embedded relationships
                        $value = $this->prepareAssociatedDocumentValue($mapping, $rawValue, $includeNestedCollections && isset($mapping['embedded']));
                        break;

                    case ClassMetadata::EMBED_MANY:
                    case ClassMetadata::REFERENCE_MANY:
                        if (! $rawValue instanceof PersistentCollectionInterface) {
                            throw new UnexpectedValueException('Expected an EMBED_MANY/REFERENCE_MANY field value to be a PersistentCollectionInterface.');
                        }

                        $value = $this->prepareAssociatedCollectionValue($rawValue, $includeNestedCollections);
                        break;

                    default:
                        throw new UnexpectedValueException('Unsupported mapping association: ' . $mapping['association']);
                }
            }

            // Omit non-nullable fields that would have a null value
            if ($value === null && ($mapping['nullable'] ?? false) === false) {
                continue;
            }

            $embeddedDocumentValue[$mapping['name']] = $value;
        }

        /* Add a discriminator value if the embedded document is not mapped
         * explicitly to a targetDocument class.
         */
        if (! isset($embeddedMapping['targetDocument'])) {
            $discriminatorField = $embeddedMapping['discriminatorField'] ?? null;
            if ($discriminatorField === null) {
                throw new UnexpectedValueException('Expected an embedded mapping without a target document to declare a discriminator field.');
            }

            if (! empty($embeddedMapping['discriminatorMap'])) {
                $discriminatorValue = array_search($class->name, $embeddedMapping['discriminatorMap']);

                if ($discriminatorValue === false) {
                    throw MappingException::unlistedClassInDiscriminatorMap($class->name);
                }
            } else {
                $discriminatorValue = $class->name;
            }

            $embeddedDocumentValue[$discriminatorField] = $discriminatorValue;
        }

        /* If the class has a discriminator (field and value), use it. A child
         * class that is not defined in the discriminator map may only have a
         * discriminator field and no value, so default to the full class name.
         */
        if (isset($class->discriminatorField)) {
            $discriminatorValue = $class->discriminatorValue;

            if ($discriminatorValue === null) {
                if (! empty($class->discriminatorMap)) {
                    throw MappingException::unlistedClassInDiscriminatorMap($class->name);
                }

                $discriminatorValue = $class->name;
            }

            $embeddedDocumentValue[$class->discriminatorField] = $discriminatorValue;
        }

        // Ensure empty embedded documents are stored as BSON objects
        if (empty($embeddedDocumentValue)) {
            return (object) $embeddedDocumentValue;
        }

        return $embeddedDocumentValue;
    }

    /**
     * @param FieldMapping $mapping
     *
     * @return array<string, mixed>|object|null
     */
    private function prepareAssociatedDocumentValue(array $mapping, object $document, bool $includeNestedCollections = false): array|object|null
    {
        if (isset($mapping['embedded'])) {
            return $this->prepareEmbeddedDocumentValue($mapping, $document, $includeNestedCollections);
        }

        if (isset($mapping['reference'])) {
            return $this->prepareReferencedDocumentValue($mapping, $document);
        }

        throw new InvalidArgumentException('Mapping is neither embedded nor reference.');
    }

    /**
     * Returns the collection representation to be stored and unschedules it afterwards.
     *
     * @param PersistentCollectionInterface<array-key, object> $coll
     *
     * @return mixed[]
     */
    private function prepareAssociatedCollectionValue(PersistentCollectionInterface $coll, bool $includeNestedCollections = false): array
    {
        // See the class docblock: PHPStan cannot resolve ClassMetadata's own
        // FieldMapping alias, and PersistentCollectionInterface::getMapping()
        // has the same @phpstan-return FieldMapping annotation. FieldMapping is
        // a PHPStan-only array-shape alias, not a real class, so it cannot be
        // asserted with instanceof.
        // phpcs:ignore SlevomatCodingStandard.PHP.RequireExplicitAssertion.RequiredExplicitAssertion
        /** @var FieldMapping $mapping */
        $mapping = $coll->getMapping();
        $pb      = $this;
        $setData = isset($mapping['embedded'])
            ? $coll->map(static fn (object $v) => $pb->prepareEmbeddedDocumentValue($mapping, $v, $includeNestedCollections))->toArray()
            : $coll->map(static fn (object $v) => $pb->prepareReferencedDocumentValue($mapping, $v))->toArray();
        if (CollectionHelper::isList($mapping['strategy'] ?? null)) {
            $setData = array_values($setData);
        }

        return $setData;
    }
}
