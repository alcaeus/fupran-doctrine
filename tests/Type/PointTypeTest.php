<?php

declare(strict_types=1);

namespace App\Tests\Type;

use App\Type\PointType;
use Doctrine\ODM\MongoDB\Types\Type;
use GeoJson\Geometry\Point;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

class PointTypeTest extends TestCase
{
    private PointType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Type::registerType('point', PointType::class);

        $this->type = Type::getType('point');
    }

    public function testConvertToDatabaseValue(): void
    {
        $point = new Point([1.5, 2.5]);

        self::assertEquals(
            (object) ['type' => 'Point', 'coordinates' => [1.5, 2.5]],
            $this->type->convertToDatabaseValue($point),
        );
    }

    public function testConvertToDatabaseValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null));
    }

    public function testConvertToPHPValue(): void
    {
        $value = ['type' => 'Point', 'coordinates' => [1.5, 2.5]];

        $point = $this->type->convertToPHPValue($value);

        self::assertInstanceOf(Point::class, $point);
        self::assertSame([1.5, 2.5], $point->getCoordinates());
    }

    public static function dataConvertToPHPValueRejectsInvalidData(): iterable
    {
        yield 'not an array' => ['foo'];
        yield 'missing type' => [['coordinates' => [1.5, 2.5]]];
        yield 'wrong type' => [['type' => 'LineString', 'coordinates' => [1.5, 2.5]]];
        yield 'missing coordinates' => [['type' => 'Point']];
        yield 'coordinates not an array' => [['type' => 'Point', 'coordinates' => 'foo']];
        yield 'coordinates with wrong keys' => [['type' => 'Point', 'coordinates' => ['lat' => 1.5, 'lng' => 2.5]]];
        yield 'coordinates with too few elements' => [['type' => 'Point', 'coordinates' => [1.5]]];
        yield 'coordinates with too many elements' => [['type' => 'Point', 'coordinates' => [1.5, 2.5, 3.5]]];
    }

    #[DataProvider('dataConvertToPHPValueRejectsInvalidData')]
    public function testConvertToPHPValueRejectsInvalidData(mixed $value): void
    {
        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('Invalid data received for Point');

        $this->type->convertToPHPValue($value);
    }

    public function testClosureToMongoMatchesConvertToDatabaseValue(): void
    {
        $value = new Point([1.5, 2.5]);

        eval($this->type->closureToMongo());

        self::assertEquals($this->type->convertToDatabaseValue($value), $return);
    }

    public function testClosureToPHPMatchesConvertToPHPValue(): void
    {
        $value = ['type' => 'Point', 'coordinates' => [1.5, 2.5]];

        eval($this->type->closureToPHP());

        self::assertEquals($this->type->convertToPHPValue($value), $return);
    }
}
