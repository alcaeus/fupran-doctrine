<?php

declare(strict_types=1);

namespace App\FileImport;

use MongoDB\Driver\WriteResult;
use UnexpectedValueException;

use function count;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class ImportResult
{
    public static function fromWriteResult(WriteResult $writeResult): static
    {
        return new static(
            $writeResult->getInsertedCount() + $writeResult->getUpsertedCount(),
            count($writeResult->getWriteErrors()),
            $writeResult->getModifiedCount(),
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (
            ! is_array($data)
            || ! is_int($data['inserted'] ?? null)
            || ! is_int($data['skipped'] ?? null)
            || ! is_int($data['updated'] ?? null)
        ) {
            throw new UnexpectedValueException(sprintf('Cannot read import result from "%s".', $json));
        }

        return new self($data['inserted'], $data['skipped'], $data['updated']);
    }

    public function toJson(string $file): string
    {
        return json_encode(
            ['file' => $file, 'inserted' => $this->numInserted, 'skipped' => $this->numSkipped, 'updated' => $this->numUpdated],
            JSON_THROW_ON_ERROR,
        );
    }

    public function __construct(
        public readonly int $numInserted = 0,
        public readonly int $numSkipped = 0,
        public readonly int $numUpdated = 0,
    ) {
    }

    public function withResult(self $result): self
    {
        return new self(
            $this->numInserted + $result->numInserted,
            $this->numSkipped + $result->numSkipped,
            $this->numUpdated + $result->numUpdated,
        );
    }
}
