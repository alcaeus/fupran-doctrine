<?php

declare(strict_types=1);

namespace App\Aggregation;

use App\Fuel;
use Generator;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Builder\Accumulator;
use MongoDB\Builder\Expression;
use MongoDB\Builder\Pipeline;
use MongoDB\Builder\Stage;
use MongoDB\Collection;
use UnexpectedValueException;

use function count;
use function intdiv;
use function is_array;
use function is_int;
use function min;

/**
 * Splits the stations that have price reports into chunks that produce a bounded number of output documents.
 *
 * Only stations from the Station collection are considered, as reports of unknown stations cannot be
 * aggregated anyway. The size of a chunk is estimated from the import collection: this is a single pass over
 * the {station, date} index without fetching documents.
 */
final class StationChunker
{
    public const int GROUPS_PER_CHUNK = 80_000;
    public const int STATIONS_PER_CHUNK = 500;

    private const int MILLISECONDS_IN_DAY = 86_400_000;

    /**
     * Upper bound for the number of (day, fuel) groups per station: there cannot be more groups than
     * reports, nor more than one group per fuel for every day between the first and the last report.
     *
     * @return array<string, int> Number of groups, keyed by the raw bytes of the station UUID
     */
    public function estimateGroups(Collection $importCollection): array
    {
        $cursor = $importCollection->aggregate(
            new Pipeline(
                Stage::group(
                    _id: Expression::fieldPath('station'),
                    reports: Accumulator::sum(1),
                    firstDate: Accumulator::min(Expression::dateFieldPath('date')),
                    lastDate: Accumulator::max(Expression::dateFieldPath('date')),
                ),
            ),
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );

        $estimates = [];
        foreach ($cursor as $station) {
            if (
                ! is_array($station)
                || ! $station['_id'] instanceof Binary
                || ! $station['firstDate'] instanceof UTCDateTime
                || ! $station['lastDate'] instanceof UTCDateTime
                || ! is_int($station['reports'])
            ) {
                throw new UnexpectedValueException('Unexpected station estimate.');
            }

            $days = intdiv((int) (string) $station['lastDate'] - (int) (string) $station['firstDate'], self::MILLISECONDS_IN_DAY) + 1;
            $estimates[$station['_id']->getData()] = min($station['reports'], $days * count(Fuel::cases()));
        }

        return $estimates;
    }

    /**
     * @param array<string, int> $estimates  See estimateGroups()
     * @param iterable<Binary>   $stationIds Stations in the order they are to be processed
     *
     * @return list<list<Binary>>
     */
    public function createChunks(array $estimates, iterable $stationIds, int $maxGroups = self::GROUPS_PER_CHUNK, int $maxStations = self::STATIONS_PER_CHUNK): array
    {
        $chunks = [];
        $chunk = [];
        $groups = 0;

        foreach ($stationIds as $stationId) {
            $estimate = $estimates[$stationId->getData()] ?? 0;
            if ($estimate === 0) {
                continue;
            }

            // A station that does not fit starts a new chunk; one that is too large on its own gets a chunk to itself
            if ($chunk !== [] && ($groups + $estimate > $maxGroups || count($chunk) >= $maxStations)) {
                $chunks[] = $chunk;
                $chunk = [];
                $groups = 0;
            }

            $chunk[] = $stationId;
            $groups += $estimate;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /** @return list<list<Binary>> */
    public function planChunks(Collection $importCollection, Collection $stationCollection): array
    {
        $estimates = $this->estimateGroups($importCollection);

        $stationIds = (static function () use ($stationCollection): Generator {
            $stations = $stationCollection->find([], [
                'projection' => ['_id' => 1],
                'sort' => ['_id' => 1],
                'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
            ]);

            foreach ($stations as $station) {
                if (! is_array($station) || ! $station['_id'] instanceof Binary) {
                    throw new UnexpectedValueException('Unexpected station id.');
                }

                yield $station['_id'];
            }
        })();

        return $this->createChunks($estimates, $stationIds);
    }
}
