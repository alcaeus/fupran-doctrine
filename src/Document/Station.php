<?php

declare(strict_types=1);

namespace App\Document;

use App\Document\Partial\AbstractStation;
use App\Repository\StationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceMany;
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

    #[EmbedOne(targetDocument: LatestPriceReport::class)]
    public ?LatestPriceReport $latestPrice = null;

    // Use a repository method as we can't load based on an embedded document
    /** @var Collection<int, DailyPrice::class> $latestPrices */
    #[ReferenceMany(targetDocument: DailyPrice::class, /*mappedBy: 'station._id',*/ repositoryMethod: 'getLatestPricesForStation')]
    private Collection $latestPrices;

    /** @var Collection<int, DailyPrice::class> $last30DaysDiesel */
    #[ReferenceMany(targetDocument: DailyPrice::class, repositoryMethod: 'getLast30DaysDieselForStation')]
    public Collection $last30DaysDiesel;

    /** @var Collection<int, DailyPrice::class> $last30DaysE5 */
    #[ReferenceMany(targetDocument: DailyPrice::class, repositoryMethod: 'getLast30DaysE5ForStation')]
    public Collection $last30DaysE5;

    /** @var Collection<int, DailyPrice::class> $last30DaysE10 */
    #[ReferenceMany(targetDocument: DailyPrice::class, repositoryMethod: 'getLast30DaysE10ForStation')]
    public Collection $last30DaysE10;

    public function __construct(string|UuidV4|null $id = null)
    {
        $this->id = $id instanceof UuidV4 ? $id : new UuidV4($id);
        $this->latestPrices = new ArrayCollection();
    }
}
