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
class Station extends AbstractStation
{
    #[Id(type: 'binaryUuid', strategy: 'none')]
    public readonly Uuid $id;

    #[EmbedOne(targetDocument: Address::class)]
    public Address $address;

    #[EmbedOne(targetDocument: DailyPriceReport::class)]
    public ?DailyPriceReport $latestPrice = null;

    #[EmbedOne(targetDocument: DailyPriceReports::class)]
    public ?DailyPriceReports $latestPrices = null;

    #[Field]
    #[Index]
    public bool $favorite = false;

    public function __construct(string|UuidV4|null $id = null)
    {
        $this->id = $id instanceof UuidV4 ? $id : new UuidV4($id);
    }
}
