<?php

declare(strict_types=1);

namespace App\Tests\Twig\Component;

use App\DateRange;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

class DateRangePickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersFormTargetingFrame(): void
    {
        $crawler = $this->render(['min' => self::day('2024-01-01')]);

        $form = $crawler->filter('form');
        self::assertSame('get', $form->attr('method'));
        self::assertSame('/', $form->attr('action'));
        self::assertSame('charts', $form->attr('data-turbo-frame'));
        self::assertSame('advance', $form->attr('data-turbo-action'));

        self::assertSame('2024-11-01', $crawler->filter('input[name=from]')->attr('value'));
        self::assertSame('2024-11-30', $crawler->filter('input[name=to]')->attr('value'));
        self::assertSame('2024-01-01', $crawler->filter('input[name=from]')->attr('min'));
        self::assertSame('2024-12-15', $crawler->filter('input[name=to]')->attr('max'));
    }

    public function testRendersPresets(): void
    {
        $links = $this->render()->filter('a.btn');

        self::assertCount(4, $links);
        self::assertSame('/?from=2024-12-09&to=2024-12-15', $links->eq(0)->attr('href'));
        self::assertSame('/?from=2024-11-16&to=2024-12-15', $links->eq(1)->attr('href'));
        self::assertSame('/?from=2024-09-17&to=2024-12-15', $links->eq(2)->attr('href'));
        self::assertSame('/?from=2024-01-01&to=2024-12-15', $links->eq(3)->attr('href'));
        self::assertSame('charts', $links->eq(0)->attr('data-turbo-frame'));
    }

    /** @param array<string, mixed> $props */
    private function render(array $props = []): Crawler
    {
        return $this->renderTwigComponent('DateRangePicker', $props + [
            'range' => new DateRange(self::day('2024-11-01'), self::day('2024-11-30'), 366),
            'frame' => 'charts',
            'routeName' => 'app_homepage',
            'today' => self::day('2024-12-15'),
        ])->crawler();
    }

    private static function day(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }
}
