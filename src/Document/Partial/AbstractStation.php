<?php

declare(strict_types=1);

namespace App\Document\Partial;

use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;
use Doctrine\ODM\MongoDB\Mapping\Annotations\MappedSuperclass;
use GeoJson\Geometry\Point;

#[MappedSuperclass]
#[Index(keys: ['location' => '2dsphere'])]
abstract class AbstractStation
{
    #[Field]
    public string $name;

    #[Field]
    public string $brand;

    #[Field(type: 'point')]
    public Point $location;
}
