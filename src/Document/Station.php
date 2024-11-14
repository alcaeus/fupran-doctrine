<?php

namespace App\Document;

use App\Document\Partial\AbstractStation;
use App\Repository\StationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\Annotations\HasLifecycleCallbacks;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;
use Doctrine\ODM\MongoDB\Mapping\Annotations\PostLoad;
use Doctrine\ODM\MongoDB\Mapping\Annotations\ReferenceMany;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

#[Document(repositoryClass: StationRepository::class)]
#[HasLifecycleCallbacks]
class Station extends AbstractStation
{
    #[Id(type: 'binaryUuid', strategy: 'none')]
    public readonly Uuid $id;

    #[EmbedOne(targetDocument: Address::class)]
    public Address $address;

    public LatestPriceReport $latestPriceReport;

    // TODO: can we do this a better way?
    #[ReferenceMany(targetDocument: DailyPrice::class, repositoryMethod: 'getLatestPriceForStation')]
    /** @var Collection<int, DailyPrice::class> $latestPrices */
    private Collection $latestPrices;

    public function __construct(string|UuidV4|null $id = null)
    {
        $this->id = $id instanceof UuidV4 ? $id : new UuidV4($id);
        $this->latestPrices = new ArrayCollection();
    }

    #[PostLoad]
    public function postLoad(): void
    {
        $this->latestPriceReport = new LatestPriceReport($this->latestPrices);
    }
}
