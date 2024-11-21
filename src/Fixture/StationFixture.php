<?php

declare(strict_types=1);

namespace App\Fixture;

use App\Document\Address;
use App\Document\Station;
use Doctrine\Bundle\MongoDBBundle\Fixture\Fixture;
use Doctrine\Persistence\ObjectManager;
use GeoJson\Geometry\Point;

class StationFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $station = new Station('278130b1-e062-4a0f-80cc-19e486b4c024');
        $station->name = 'Aral Tankstelle';
        $station->brand = 'ARAL';
        $station->address = new Address('Holzmarktstraße', '12/14', '10179', 'Berlin');
        $station->location = new Point([13.4214869, 52.5141525]);

        $manager->persist($station);
        $manager->flush();
    }
}
