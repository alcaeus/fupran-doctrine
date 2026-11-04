<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractStation;
use App\Repository\StationRepository;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Index;
use Doctrine\ODM\MongoDB\Mapping\Annotations\SearchIndex;
use Doctrine\ODM\MongoDB\Types\Type;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

#[Document(repositoryClass: StationRepository::class)]
#[SearchIndex(
    name: 'station',
    fields: [
        'name' => [
            ['type' => 'autocomplete'],
        ],
        'address.street' => [
            ['type' => 'autocomplete'],
        ],
        'address.city' => [
            ['type' => 'autocomplete'],
        ],
        'address.postCode' => [
            ['type' => 'autocomplete'],
        ],
    ],
)]
#[Index(keys: ['visible' => 'asc', '_id' => 'asc'])]
class Station extends AbstractStation
{
    #[Id(type: Type::UUID, strategy: 'none')]
    public readonly Uuid $id;

    #[EmbedOne(targetDocument: Address::class)]
    public Address $address;

    #[EmbedOne(targetDocument: LatestPrice::class)]
    public ?LatestPrice $latestPrice = null;

    #[EmbedOne(targetDocument: LatestPrices::class)]
    public ?LatestPrices $latestPrices = null;

    #[Field]
    #[Index]
    public bool $favorite = false;

    /** Set when latest prices are written to the station; stations without prices are left out of listings. */
    #[Field]
    public bool $visible = false;

    public function __construct(string|UuidV4|null $id = null)
    {
        $this->id = $id instanceof UuidV4 ? $id : new UuidV4($id);
    }
}
