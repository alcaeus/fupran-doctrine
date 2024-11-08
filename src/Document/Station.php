<?php

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use GeoJson\Geometry\Point;
use Symfony\Component\Uid\UuidV4;

#[Document]
class Station
{
    #[Id(type: 'binaryUuid', strategy: 'none')]
    public readonly UuidV4 $id;

    #[Field]
    public string $name;

    #[Field]
    public string $brand;

    #[EmbedOne(targetDocument: Address::class)]
    public Address $address;

    #[Field(type: 'point')]
    public Point $location;

    public function __construct(string|UuidV4|null $id = null)
    {
        $this->id = $id instanceof UuidV4 ? $id : new UuidV4($id);
    }
}
