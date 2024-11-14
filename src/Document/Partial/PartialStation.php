<?php

namespace App\Document\Partial;

use App\Document\Station;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbeddedDocument;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceOne;

#[EmbeddedDocument]
class PartialStation extends AbstractStation
{
    #[ReferenceOne(name: '_id', storeAs: 'id', targetDocument: Station::class)]
    #[Index]
    private Station $referencedStation;

    #[EmbedOne(targetDocument: PartialAddress::class)]
    public PartialAddress $address;
}
