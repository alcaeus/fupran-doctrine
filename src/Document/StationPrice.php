<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute\Field;
use Doctrine\ODM\MongoDB\Mapping\Attribute\QueryResultDocument;
use Doctrine\ODM\MongoDB\Types\Type;
use GeoJson\Geometry\Point;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use UnexpectedValueException;

use function is_float;
use function is_int;

/**
 * A station with its closing price of a day, for markers on the post code map. This is all the map needs to know of a
 * daily price, so it is the only thing sent to the client.
 */
#[QueryResultDocument]
class StationPrice
{
    #[Field(type: Type::UUID, name: '_id')]
    public readonly Uuid $id;

    #[Field]
    public readonly string $name;

    #[Field]
    public readonly string $brand;

    #[Field(type: 'point')]
    #[Ignore]
    public readonly Point $location;

    #[Field]
    public readonly float $price;

    public float $latitude {
        get => self::toFloat($this->location->getCoordinates()[1]);
    }

    public float $longitude {
        get => self::toFloat($this->location->getCoordinates()[0]);
    }

    /**
     * Doctrine ODM hydrates this document via reflection, bypassing the
     * constructor. It is declared for manual construction and to satisfy
     * static analysis of the readonly properties above.
     */
    public function __construct(Uuid $id, string $name, string $brand, Point $location, float $price)
    {
        $this->id = $id;
        $this->name = $name;
        $this->brand = $brand;
        $this->location = $location;
        $this->price = $price;
    }

    private static function toFloat(mixed $coordinate): float
    {
        if (! is_int($coordinate) && ! is_float($coordinate)) {
            throw new UnexpectedValueException('Expected coordinate to be a number.');
        }

        return (float) $coordinate;
    }
}
