<?php

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
        $station = new Station();
        $station->name = 'Acme München';
        $station->brand = 'Acme Corp.';
        $station->address = new Address('Superman Rd.', '15', '80639', 'München');
        $station->location = new Point([0, 0]);

        $manager->persist($station);
        $manager->flush();
    }
}
