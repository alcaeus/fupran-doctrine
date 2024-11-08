<?php

namespace App\Import;

use MongoDB\Driver\WriteResult;

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

    public function __construct(
        public readonly int $numInserted = 0,
        public readonly int $numSkipped = 0,
        public readonly int $numUpdated = 0,
    ) {}

    public function withResult(self $result)
    {
        return new self(
            $this->numInserted + $result->numInserted,
            $this->numSkipped + $result->numSkipped,
            $this->numUpdated + $result->numUpdated,
        );
    }
}
