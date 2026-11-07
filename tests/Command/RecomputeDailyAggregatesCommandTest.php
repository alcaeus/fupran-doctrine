<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RecomputeDailyAggregatesCommand;
use App\Document\DailyAggregate;
use App\Document\DailyPrice;
use App\Document\PostCodeDailyAggregate;
use App\Fuel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ODM\MongoDB\DocumentManager;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function sort;

class RecomputeDailyAggregatesCommandTest extends KernelTestCase
{
    private const array DAYS = ['2026-08-14', '2026-08-15', '2026-08-16', '2026-08-17'];

    protected function setUp(): void
    {
        parent::setUp();

        $documentManager = self::getDocumentManager();
        $documentManager->getDocumentCollection(DailyPrice::class)->deleteMany([]);
        $documentManager->getDocumentCollection(DailyAggregate::class)->deleteMany([]);
        $documentManager->getDocumentCollection(PostCodeDailyAggregate::class)->deleteMany([]);

        // $merge on ['day', 'fuel'] requires a unique index covering exactly those fields
        $documentManager->getDocumentCollection(DailyAggregate::class)
            ->createIndex(['day' => 1, 'fuel' => 1], ['unique' => true]);
        $documentManager->getDocumentCollection(PostCodeDailyAggregate::class)
            ->createIndex(['day' => 1, 'fuel' => 1, 'postCode' => 1], ['unique' => true]);

        // Two stations per day: 1 + 3 increases, 0 + 2 decreases
        foreach (self::DAYS as $day) {
            self::insertDailyPrice($day, 'station-a', ['increases' => 1, 'decreases' => 0, 'total' => 1], '10115');
            self::insertDailyPrice($day, 'station-b', ['increases' => 3, 'decreases' => 2, 'total' => 5], '80331');
        }
    }

    public function testRecomputesAllDays(): void
    {
        $this->recompute([]);

        self::assertSame(self::DAYS, $this->getAggregatedDays());

        $aggregate = $this->findAggregate('2026-08-15');
        self::assertSame(['increases' => 4, 'decreases' => 2, 'total' => 6], $aggregate['totalChanges']);
        self::assertEquals(['increases' => 2.0, 'decreases' => 1.0, 'total' => 3.0], $aggregate['averageChanges']);
        self::assertArrayNotHasKey('numChanges', $aggregate);
    }

    public function testRecomputesAggregatesPerPostCode(): void
    {
        $this->recompute(['--from' => '2026-08-15', '--to' => '2026-08-15']);

        $aggregates = self::getDocumentManager()->getRepository(PostCodeDailyAggregate::class)->findBy([], ['postCode' => 'asc']);

        self::assertCount(2, $aggregates);
        self::assertSame('10115', $aggregates[0]->postCode);
        self::assertSame(1, $aggregates[0]->totalChanges->increases);
        self::assertSame(1.0, $aggregates[0]->averageChanges->increases);
        self::assertSame('80331', $aggregates[1]->postCode);
        self::assertSame(3, $aggregates[1]->totalChanges->increases);
        self::assertSame(3.0, $aggregates[1]->averageChanges->increases);
    }

    public function testRecomputedAggregatesCanBeHydrated(): void
    {
        $this->recompute([]);

        $aggregate = self::getDocumentManager()->getRepository(DailyAggregate::class)->findOneBy(['fuel' => Fuel::Diesel->value]);

        self::assertInstanceOf(DailyAggregate::class, $aggregate);
        self::assertSame(4, $aggregate->totalChanges->increases);
        self::assertSame(2, $aggregate->totalChanges->decreases);
        self::assertSame(6, $aggregate->totalChanges->total);
        self::assertSame(2.0, $aggregate->averageChanges->increases);
        self::assertSame(1.0, $aggregate->averageChanges->decreases);
        self::assertSame(3.0, $aggregate->averageChanges->total);
    }

    public function testRestrictsToRange(): void
    {
        $this->recompute(['--from' => '2026-08-15', '--to' => '2026-08-16']);

        self::assertSame(['2026-08-15', '2026-08-16'], $this->getAggregatedDays());
    }

    public function testFromWithoutTo(): void
    {
        $this->recompute(['--from' => '2026-08-16']);

        self::assertSame(['2026-08-16', '2026-08-17'], $this->getAggregatedDays());
    }

    public function testToWithoutFrom(): void
    {
        $this->recompute(['--to' => '2026-08-15']);

        self::assertSame(['2026-08-14', '2026-08-15'], $this->getAggregatedDays());
    }

    public function testRecomputesReplacesExistingAggregates(): void
    {
        $this->recompute([]);
        self::getDocumentManager()->getDocumentCollection(DailyPrice::class)->updateMany(
            ['station._id' => 'station-a'],
            ['$set' => ['changes.increases' => 5, 'changes.total' => 5]],
        );

        $this->recompute(['--from' => '2026-08-15', '--to' => '2026-08-15']);

        self::assertSame(8, $this->findAggregate('2026-08-15')['totalChanges']['increases']);
        // Days outside the range are left alone
        self::assertSame(4, $this->findAggregate('2026-08-14')['totalChanges']['increases']);
    }

    public function testRecomputesInParallel(): void
    {
        $this->recompute(['--jobs' => 2]);

        self::assertSame(self::DAYS, $this->getAggregatedDays());
        self::assertSame(['increases' => 4, 'decreases' => 2, 'total' => 6], $this->findAggregate('2026-08-17')['totalChanges']);
    }

    public function testParallelRecomputeIsRestrictedToRange(): void
    {
        $this->recompute(['--jobs' => 4, '--from' => '2026-08-15', '--to' => '2026-08-17']);

        self::assertSame(['2026-08-15', '2026-08-16', '2026-08-17'], $this->getAggregatedDays());
    }

    public function testWithoutDaysInRangeNothingIsComputed(): void
    {
        $tester = $this->recompute(['--from' => '2020-01-01', '--to' => '2020-01-31']);

        self::assertSame([], $this->getAggregatedDays());
        self::assertStringContainsString('no days with prices', $tester->getDisplay());
    }

    public function testOnlyPostCodeAggregates(): void
    {
        $this->recompute(['--only' => ['post-code']]);

        self::assertSame([], $this->getAggregatedDays());
        self::assertSame(8, self::getDocumentManager()->getDocumentCollection(PostCodeDailyAggregate::class)->countDocuments());
    }

    public function testOnlyDailyAggregates(): void
    {
        $this->recompute(['--only' => ['daily']]);

        self::assertSame(self::DAYS, $this->getAggregatedDays());
        self::assertSame(0, self::getDocumentManager()->getDocumentCollection(PostCodeDailyAggregate::class)->countDocuments());
    }

    public function testOnlyIsPassedToParallelWorkers(): void
    {
        $this->recompute(['--only' => ['post-code'], '--jobs' => 2]);

        self::assertSame([], $this->getAggregatedDays());
        self::assertSame(8, self::getDocumentManager()->getDocumentCollection(PostCodeDailyAggregate::class)->countDocuments());
    }

    public function testUnknownOnlyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "only" option to be one of: daily, post-code.');

        $this->recompute(['--only' => ['weekly']]);
    }

    public function testInvalidJobsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected "jobs" option to be a positive integer.');

        $this->recompute(['--jobs' => 0]);
    }

    public function testInvalidDayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "from" date must be formatted as Y-m-d.');

        $this->recompute(['--from' => '15.08.2026']);
    }

    public function testRangeInWrongOrderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->recompute(['--from' => '2026-08-16', '--to' => '2026-08-15']);
    }

    /** @param array<string, mixed> $options */
    private function recompute(array $options): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(RecomputeDailyAggregatesCommand::class));
        $tester->execute($options);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    /** @return list<string> */
    private function getAggregatedDays(): array
    {
        /** @var list<UTCDateTime> $days */
        $days = self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->distinct('day');

        $days = array_map(static fn (UTCDateTime $day): string => $day->toDateTime()->format('Y-m-d'), $days);
        sort($days);

        return $days;
    }

    /** @return array{totalChanges: array<string, int>, averageChanges: array<string, float>} */
    private function findAggregate(string $day): array
    {
        $aggregate = self::getDocumentManager()
            ->getDocumentCollection(DailyAggregate::class)
            ->findOne(
                ['fuel' => Fuel::Diesel->value, 'day' => new UTCDateTime(new DateTimeImmutable($day, new DateTimeZone('UTC')))],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            );

        self::assertIsArray($aggregate);

        /** @var array{totalChanges: array<string, int>, averageChanges: array<string, float>} $aggregate */
        $aggregate = $aggregate;

        return $aggregate;
    }

    /** @param array<string, int> $changes */
    private static function insertDailyPrice(string $day, string $station, array $changes, string $postCode): void
    {
        self::getDocumentManager()->getDocumentCollection(DailyPrice::class)->insertOne([
            'day' => new UTCDateTime(new DateTimeImmutable($day, new DateTimeZone('UTC'))),
            'fuel' => Fuel::Diesel->value,
            'station' => ['_id' => $station, 'address' => ['postCode' => $postCode]],
            'lowestPrice' => ['price' => 1.5],
            'highestPrice' => ['price' => 1.7],
            'weightedAveragePrice' => 1.6,
            'prices' => [],
            'changes' => $changes,
        ]);
    }

    private static function getDocumentManager(): DocumentManager
    {
        return self::getContainer()->get(DocumentManager::class);
    }
}
