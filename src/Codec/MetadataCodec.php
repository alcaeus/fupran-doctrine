<?php

declare(strict_types=1);

namespace App\Codec;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Hydrator\HydratorFactory;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadataFactory;
use Doctrine\ODM\MongoDB\Mapping\MappingException;
use Doctrine\ODM\MongoDB\PersistentCollection\PersistentCollectionInterface;
use Doctrine\ODM\MongoDB\Persisters\PersistenceBuilder;
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

/**
 * This class shamelessly duplicates a lot of logic from Doctrine's PersistenceBuilder class. It will have to be rewritten
 * to be optimised for the specific codec use cases.
 *
 * @phpstan-import-type FieldMapping from ClassMetadata
 */
final readonly class MetadataCodec implements DocumentCodec
{
    use DecodeIfSupported;
    use EncodeIfSupported;

    private function __construct(
        private string $className,
        private DocumentManager $documentManager,
        private ClassMetadataFactory $classMetadataFactory,
        private HydratorFactory $hydratorFactory,
        private ClassMetadata $classMetadata,
        private PersistenceBuilder $persistenceBuilder,
    ) {
    }

    public static function create(DocumentManager $documentManager, string $className): self
    {
        $classMetadataFactory = $documentManager->getMetadataFactory();
        $hydratorFactory = $documentManager->getHydratorFactory();
        $classMetadata = $classMetadataFactory->getMetadataFor($className);
        $persistenceBuilder = new PersistenceBuilder($documentManager, $documentManager->getUnitOfWork());

        return new self($className, $documentManager, $classMetadataFactory, $hydratorFactory, $classMetadata, $persistenceBuilder);
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

    private function getDocumentData(object $value): array
    {
        $insertData = [];
        foreach ($this->classMetadata->fieldMappings as $mapping) {
            $new = $this->classMetadata->getFieldValue($value, $mapping['fieldName']);

            if ($new === null) {
                if ($mapping['nullable']) {
                    $insertData[$mapping['name']] = null;
                }

                continue;
            }

            if (! isset($mapping['association'])) {
                $insertData[$mapping['name']] = Type::getType($mapping['type'])->convertToDatabaseValue($new);
            } elseif ($mapping['association'] === ClassMetadata::REFERENCE_ONE) {
                $insertData[$mapping['name']] = $this->prepareReferencedDocumentValue($mapping, $new);
            } elseif ($mapping['association'] === ClassMetadata::EMBED_ONE) {
                $insertData[$mapping['name']] = $this->prepareEmbeddedDocumentValue($mapping, $new);
            } elseif (
                $mapping['type'] === ClassMetadata::MANY && ! $mapping['isInverseSide']
                && (! $new->isEmpty() || $mapping['storeEmptyArray'])
            ) {
                $insertData[$mapping['name']] = $this->prepareAssociatedCollectionValue($new, true);
            }
        }

        // add discriminator if the class has one
        if (isset($class->discriminatorField)) {
            $discriminatorValue = $class->discriminatorValue;

            if ($discriminatorValue === null) {
                if (! empty($class->discriminatorMap)) {
                    throw MappingException::unlistedClassInDiscriminatorMap($class->name);
                }

                $discriminatorValue = $class->name;
            }

            $insertData[$class->discriminatorField] = $discriminatorValue;
        }

        return $insertData;
    }

    /**
     * Returns the reference representation to be stored in MongoDB.
     *
     * If the document does not have an identifier and the mapping calls for a
     * simple reference, null may be returned.
     *
     * @phpstan-param FieldMapping $referenceMapping
     *
     * @return array<string, mixed>|null
     */
    private function prepareReferencedDocumentValue(array $referenceMapping, object $document): ?array
    {
        return $this->documentManager->createReference($document, $referenceMapping);
    }

    private function prepareEmbeddedDocumentValue(array $embeddedMapping, $embeddedDocument, $includeNestedCollections = false)
    {
        $embeddedDocumentValue = [];
        $class = $this->classMetadataFactory->getMetadataFor($embeddedDocument::class);

        foreach ($class->fieldMappings as $mapping) {
            // Skip notSaved fields
            if (! empty($mapping['notSaved'])) {
                continue;
            }

            // Inline ClassMetadata::getFieldValue()
            $rawValue = $class->reflFields[$mapping['fieldName']]->getValue($embeddedDocument);

            $value = null;

            if ($rawValue !== null) {
                switch ($mapping['association'] ?? null) {
                    // @Field, @String, @Date, etc.
                    case null:
                        $value = Type::getType($mapping['type'])->convertToDatabaseValue($rawValue);
                        break;

                    case ClassMetadata::EMBED_ONE:
                    case ClassMetadata::REFERENCE_ONE:
                        // Nested collections should only be included for embedded relationships
                        $value = $this->prepareAssociatedDocumentValue($mapping, $rawValue, $includeNestedCollections && isset($mapping['embedded']));
                        break;

                    case ClassMetadata::EMBED_MANY:
                    case ClassMetadata::REFERENCE_MANY:
                        $value = $this->prepareAssociatedCollectionValue($rawValue, $includeNestedCollections);
                        break;

                    default:
                        throw new UnexpectedValueException('Unsupported mapping association: ' . $mapping['association']);
                }
            }

            // Omit non-nullable fields that would have a null value
            if ($value === null && $mapping['nullable'] === false) {
                continue;
            }

            $embeddedDocumentValue[$mapping['name']] = $value;
        }

        /* Add a discriminator value if the embedded document is not mapped
         * explicitly to a targetDocument class.
         */
        if (! isset($embeddedMapping['targetDocument'])) {
            $discriminatorField = $embeddedMapping['discriminatorField'];
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

    private function prepareAssociatedDocumentValue(array $mapping, $document, $includeNestedCollections = false)
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
        $mapping  = $coll->getMapping();
        $pb       = $this;
        $callback = isset($mapping['embedded'])
            ? static fn ($v) => $pb->prepareEmbeddedDocumentValue($mapping, $v, $includeNestedCollections)
            : static fn ($v) => $pb->prepareReferencedDocumentValue($mapping, $v);

        $setData = $coll->map($callback)->toArray();
        if (CollectionHelper::isList($mapping['strategy'])) {
            $setData = array_values($setData);
        }

        return $setData;
    }
}
