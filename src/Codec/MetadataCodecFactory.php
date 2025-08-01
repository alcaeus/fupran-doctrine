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
use function sprintf;

final class MetadataCodecFactory implements DocumentCodec
{
    use DecodeIfSupported;
    use EncodeIfSupported;

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
        return $this->managerRegistry->getManagerForClass($value::class) !== null;
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

    public function getCodec(string $className): MetadataCodec
    {
        return $this->codecs[$className] ??= $this->generateCodec($className);
    }

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
