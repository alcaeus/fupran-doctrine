<?php

declare(strict_types=1);

namespace App\Tests\Twig\Component\Chart\Station;

use App\Document\EmbeddedDailyPrice;
use App\Document\Price;
use App\Fuel;
use App\Twig\Component\Chart\Station\DayPriceOverview;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

use function array_map;

class DayPriceOverviewTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testDataStartsWithOpeningPriceAndEndsWithClosingPriceAtMidnight(): void
    {
        $data = $this->getData(self::dailyPrice(1.599, 1.549, [['2024-11-19T03:07:29Z', 1.569], ['2024-11-19T10:00:00Z', 1.549]]));

        self::assertSame(
            [
                ['x' => self::ts('2024-11-19T00:00:00Z'), 'y' => 1.599],
                ['x' => self::ts('2024-11-19T03:07:29Z'), 'y' => 1.569],
                ['x' => self::ts('2024-11-19T10:00:00Z'), 'y' => 1.549],
                ['x' => self::ts('2024-11-20T00:00:00Z'), 'y' => 1.549],
            ],
            $data,
        );
    }

    public function testDataOmitsOpeningPointWithoutOpeningPrice(): void
    {
        $data = $this->getData(self::dailyPrice(null, 1.569, [['2024-11-19T03:07:29Z', 1.569]]));

        self::assertSame(
            [
                ['x' => self::ts('2024-11-19T03:07:29Z'), 'y' => 1.569],
                ['x' => self::ts('2024-11-20T00:00:00Z'), 'y' => 1.569],
            ],
            $data,
        );
    }

    public function testDatasetUsesFuelColor(): void
    {
        $component = $this->mountTwigComponent('Chart:Station:DayPriceOverview', ['dailyPrices' => [self::dailyPrice(1.599, 1.549, [])]]);
        self::assertInstanceOf(DayPriceOverview::class, $component);

        $datasets = $component->getChart()->getData()['datasets'] ?? null;
        self::assertIsArray($datasets);
        self::assertIsArray($datasets[0]);
        self::assertSame(Fuel::Diesel->getColor(), $datasets[0]['borderColor']);
        self::assertSame(Fuel::Diesel->getColor(), $datasets[0]['backgroundColor']);
    }

    /** @return array<mixed> */
    private function getData(EmbeddedDailyPrice $dailyPrice): array
    {
        $component = $this->mountTwigComponent('Chart:Station:DayPriceOverview', ['dailyPrices' => [$dailyPrice]]);
        self::assertInstanceOf(DayPriceOverview::class, $component);

        $chartData = $component->getChart()->getData();
        $datasets = $chartData['datasets'] ?? null;
        self::assertIsArray($datasets);
        self::assertCount(1, $datasets);
        self::assertIsArray($datasets[0]);
        self::assertIsArray($datasets[0]['data']);

        return $datasets[0]['data'];
    }

    /** @param list<array{string, float}> $prices */
    private static function dailyPrice(?float $opening, float $closing, array $prices): EmbeddedDailyPrice
    {
        $dailyPrice = new EmbeddedDailyPrice();
        $dailyPrice->day = new DateTimeImmutable('2024-11-19', new DateTimeZone('UTC'));
        $dailyPrice->fuel = Fuel::Diesel;
        $dailyPrice->openingPrice = $opening;
        $dailyPrice->closingPrice = $closing;
        $dailyPrice->prices = new ArrayCollection(array_map(
            static fn (array $price): Price => new Price(new DateTimeImmutable($price[0]), $price[1]),
            $prices,
        ));

        return $dailyPrice;
    }

    private static function ts(string $date): int
    {
        return new DateTimeImmutable($date)->getTimestamp() * 1000;
    }
}
