<?php

declare(strict_types=1);

namespace App\Import;

use App\Document\DailyPrice;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Types\BinaryUuidType;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\BulkWrite;
use UnexpectedValueException;

use function array_filter;
use function sprintf;
use function strtotime;
use function uniqid;

final class PriceReportImporter extends Importer
{
    public function __construct(
        DocumentManager $documentManager,
        private readonly BinaryUuidType $binaryUuidType,
    ) {
        $tempName = uniqid('priceReportImport_');

        // Select a temporary collection that we'll be deleting afterwards
        $collection = $documentManager
            ->getDocumentDatabase(DailyPrice::class)
            ->selectCollection($tempName);

        parent::__construct($collection);
    }

    /** @param array<string, string|null> $data */
    protected function storeDocument(BulkWrite $bulk, array $data): void
    {
        foreach ($this->buildDocuments($data) as $priceReport) {
            $bulk->insert($priceReport);
        }
    }

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDocuments(array $rawData): array
    {
        return array_filter([
            $this->buildDocument($rawData, 'diesel'),
            $this->buildDocument($rawData, 'e5'),
            $this->buildDocument($rawData, 'e10'),
        ]);
    }

    /**
     * @param array<string, string|null> $rawData
     *
     * @return array<string, mixed>|null
     */
    private function buildDocument(array $rawData, string $fuelType): ?array
    {
        if ($rawData[$fuelType . 'change'] !== '1') {
            return null;
        }

        /* Check price for sanity - some price reports may include negative prices.
         * 50 cents seems like a sensible hardcoded choice, as prices in Germany are
         * unlikely to ever get to this level.
         */
        $price = (float) $rawData[$fuelType];
        if ($price < 0.5) {
            return null;
        }

        $date = $rawData['date'];
        if ($date === null) {
            throw new UnexpectedValueException('Missing "date" column in price report row.');
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new UnexpectedValueException(sprintf('Could not parse date "%s" in price report row.', $date));
        }

        return [
            'date' => new UTCDateTime($timestamp * 1000),
            'station' => $this->binaryUuidType->convertToDatabaseValue($rawData['station_uuid']),
            'fuel' => $fuelType,
            'price' => $price,
        ];
    }
}
