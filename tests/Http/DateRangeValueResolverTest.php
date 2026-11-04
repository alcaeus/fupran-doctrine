<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\DateRange;
use App\Http\DateRangeValueResolver;
use App\Http\MapDateRange;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

use function array_values;
use function iterator_to_array;

class DateRangeValueResolverTest extends TestCase
{
    public function testResolvesDefaultRangeWithoutParameters(): void
    {
        $range = $this->resolve(new Request());

        self::assertSame(7, $range->from->diff($range->to)->days + 1);
    }

    public function testResolvesRangeFromQuery(): void
    {
        $range = $this->resolve(new Request(['from' => '2026-01-01', 'to' => '2026-01-31']));

        self::assertSame('2026-01-01', $range->from->format('Y-m-d'));
        self::assertSame('2026-01-31', $range->to->format('Y-m-d'));
    }

    public function testEmptyParametersUseDefaults(): void
    {
        $range = $this->resolve(new Request(['from' => '', 'to' => '']));

        self::assertSame(7, $range->from->diff($range->to)->days + 1);
    }

    public function testInvalidRangeIsBadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolve(new Request(['from' => '2026-02-01', 'to' => '2026-01-01']));
    }

    public function testArrayParameterIsBadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolve(new Request(['from' => ['2026-01-01']]));
    }

    public function testIgnoresArgumentsWithoutAttribute(): void
    {
        $resolver = new DateRangeValueResolver(366);

        self::assertSame([], $resolver->resolve(new Request(), new ArgumentMetadata('range', DateRange::class, false, false, null)));
    }

    public function testNullableArgumentWithoutParametersIsNotResolved(): void
    {
        $argument = new ArgumentMetadata('range', DateRange::class, false, true, null, true, [new MapDateRange(7)]);

        self::assertSame([], [...(new DateRangeValueResolver(366))->resolve(new Request(), $argument)]);
    }

    public function testNullableArgumentWithParametersIsResolved(): void
    {
        $argument = new ArgumentMetadata('range', DateRange::class, false, true, null, true, [new MapDateRange(7)]);

        self::assertCount(1, [...(new DateRangeValueResolver(366))->resolve(new Request(['to' => '2026-01-10']), $argument)]);
    }

    private function resolve(Request $request): DateRange
    {
        $argument = new ArgumentMetadata('range', DateRange::class, false, false, null, false, [new MapDateRange(7)]);
        $resolved = array_values(iterator_to_array((new DateRangeValueResolver(366))->resolve($request, $argument)));

        self::assertCount(1, $resolved);

        return $resolved[0];
    }
}
