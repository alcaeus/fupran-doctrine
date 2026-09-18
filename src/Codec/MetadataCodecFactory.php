<?php

declare(strict_types=1);

namespace App\Codec;

use Doctrine\Bundle\MongoDBBundle\ManagerRegistry;
use Doctrine\ODM\MongoDB\DocumentManager;
use InvalidArgumentException;
use MongoDB\BSON\Document;
use MongoDB\Codec\DecodeIfSupported;
use MongoDB\Codec\DocumentCodec;
use MongoDB\Codec\EncodeIfSupported;
use MongoDB\Exception\UnsupportedValueException;

use function assert;
use function is_object;
use function sprintf;

/** @template-implements DocumentCodec<object> */
final class MetadataCodecFactory implements DocumentCodec
{
    /** @use DecodeIfSupported<mixed, object> */
    use DecodeIfSupported;
    /** @use EncodeIfSupported<mixed, object> */
    use EncodeIfSupported;

    /** @var array<class-string, MetadataCodec> */
    private array $codecs = [];

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function canDecode(mixed $value): bool
    {
        return false;
    }

    public function canEncode(mixed $value): bool
    {
        return is_object($value) && $this->managerRegistry->getManagerForClass($value::class) !== null;
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

        return $this->getCodec($value::class)->encode($value);
    }

    /** @param class-string $className */
    public function getCodec(string $className): MetadataCodec
    {
        return $this->codecs[$className] ??= $this->generateCodec($className);
    }

    /** @param class-string $className */
    private function generateCodec(string $className): MetadataCodec
    {
        $documentManager = $this->managerRegistry->getManagerForClass($className);
        if ($documentManager === null) {
            throw new InvalidArgumentException(sprintf('No document manager found for class: %s', $className));
        }

        assert($documentManager instanceof DocumentManager);

        return MetadataCodec::create($documentManager, $className);
    }
}
