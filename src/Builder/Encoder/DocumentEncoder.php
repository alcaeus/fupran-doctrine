<?php

declare(strict_types=1);

namespace App\Builder\Encoder;

use App\Builder\Expression\Document as DocumentExpression;
use App\Codec\MetadataCodecFactory;
use MongoDB\BSON\Document as BSONDocument;
use MongoDB\Codec\EncodeIfSupported;
use MongoDB\Codec\Encoder;
use MongoDB\Exception\UnsupportedValueException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @internal
 *
 * @template-implements Encoder<BSONDocument, DocumentExpression>
 */
#[AutoconfigureTag('mongodb.builder.encoder', ['expressionClass' => DocumentExpression::class])]
final class DocumentEncoder implements Encoder
{
    /** @template-use EncodeIfSupported<BSONDocument, DocumentExpression> */
    use EncodeIfSupported;

    public function __construct(
        private readonly MetadataCodecFactory $codec,
    ) {
    }

    public function canEncode(mixed $value): bool
    {
        return $value instanceof DocumentExpression
            && $this->codec->canEncode($value->document);
    }

    public function encode(mixed $value): BSONDocument
    {
        if (! $this->canEncode($value)) {
            throw UnsupportedValueException::invalidEncodableValue($value);
        }

        return $this->codec->encode($value->document);
    }
}
