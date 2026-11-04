<?php

declare(strict_types=1);

namespace App\Aggregation;

use MongoDB\BSON\Binary;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use UnexpectedValueException;

use function array_keys;
use function array_map;
use function is_array;
use function is_int;

/**
 * Hands out chunks of stations to workers. Every worker claims the next pending chunk atomically, so
 * workers that draw cheap chunks simply come back for more instead of idling.
 */
final class ChunkQueue
{
    public function __construct(private readonly Collection $collection)
    {
    }

    public function getCollectionName(): string
    {
        return $this->collection->getCollectionName();
    }

    /** @param list<list<Binary>> $chunks */
    public function enqueue(array $chunks): void
    {
        if ($chunks === []) {
            return;
        }

        $this->collection->insertMany(array_map(
            static fn (array $stations, int $id): array => ['_id' => $id, 'stations' => $stations, 'state' => 'pending'],
            $chunks,
            array_keys($chunks),
        ));
    }

    /** @return array{id: int, stations: list<Binary>}|null */
    public function claim(): ?array
    {
        $chunk = $this->collection->findOneAndUpdate(
            ['state' => 'pending'],
            ['$set' => ['state' => 'running']],
            [
                'sort' => ['_id' => 1],
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ],
        );

        if ($chunk === null) {
            return null;
        }

        if (! is_array($chunk) || ! is_int($chunk['_id']) || ! is_array($chunk['stations'])) {
            throw new UnexpectedValueException('Unexpected chunk document in queue.');
        }

        $stations = [];
        foreach ($chunk['stations'] as $station) {
            if (! $station instanceof Binary) {
                throw new UnexpectedValueException('Unexpected station in chunk.');
            }

            $stations[] = $station;
        }

        return ['id' => $chunk['_id'], 'stations' => $stations];
    }

    public function complete(int $id): void
    {
        $this->collection->updateOne(['_id' => $id], ['$set' => ['state' => 'done']]);
    }

    public function drop(): void
    {
        $this->collection->drop();
    }
}
