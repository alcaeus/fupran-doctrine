# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project is

A Symfony 8.1 / PHP 8.4 app that ingests German fuel-price data (MTS-K / Tankerkönig-style "price reports" and "stations" CSV exports) into MongoDB via Doctrine ODM, pre-aggregates it into daily price and daily aggregate documents, and serves it through Twig pages (station list/search/detail, ChartJS price history, daily-aggregate dashboard).

## Commands

Run via Symfony CLI wrapper (see memory: `symfony php ...` for PHPUnit, not bare `vendor/bin/phpunit`); it exports the dynamic Docker MongoDB server as `MDB_SERVER`, from which `.env` builds `MONGODB_URL` (tests must use that, not a hardcoded host).

- Tests: `symfony php vendor/bin/phpunit` (single test: `symfony php vendor/bin/phpunit --filter testName tests/Path/To/Test.php`)
- Static analysis: `composer phpstan` (warms the dev cache first, then runs `phpstan analyse`; config in `phpstan.dist.neon`)
- Coding standard: `vendor/bin/phpcs` (config `phpcs.xml.dist`, doctrine/coding-standard based); check `.phpcs-cache` exists so incremental runs are fast
- Console commands: `bin/console <command>` (or `symfony console ...`)
  - `app:import:stations <fileOrDirectory>...` `[--clear]`
  - `app:import:price-reports <fileOrDirectory>...` `[--recover] [--clear] [--jobs=N] [--import-jobs=N] [--aggregation-jobs=N]`
  - `app:data:compute:daily-aggregates`

## Data ingestion

Two independent CSV ingestion pipelines, both driven by `App\Import\Importer` (abstract base: streams a CSV with `fgetcsv`, builds a `MongoDB\Driver\BulkWrite` per row via `storeDocument()`, and executes it against a raw `MongoDB\Collection`, i.e. **outside** the Doctrine ODM unit of work). A directory argument imports every `*.csv` file found via Symfony Finder.

- **Stations** (`StationImporter`, fixtures under `fixtures/stations/*.csv`, columns: `uuid,name,brand,street,house_number,post_code,city,latitude,longitude,first_active,openingtimes_json`): upserted directly into the `Station` collection, keyed by `uuid` (converted to a BSON `Binary` via `BinaryUuidType`). Name/street/city are capitalization-normalized. `location` (GeoJSON Point) is only set if lat/long are within valid ranges.
- **Price reports** (`PriceReportImporter`, fixtures under `fixtures/prices/*.csv`, columns: `date,station_uuid,diesel,e5,e10,dieselchange,e5change,e10change`): only rows where the fuel's `*change` flag is `"1"` are kept, and prices `< 0.5` are rejected as a sanity check (negative/garbage prices in source data). One row can yield up to 3 raw price-report documents (one per fuel). These are inserted into a **temporary** per-import collection (`priceReportImport_<uniqid>`), not directly into `DailyPrice`.

### Price-report post-processing pipeline

`ImportPriceReportsCommand` orchestrates a multi-stage server-side aggregation (`App\Aggregation\PriceReport`, built with `mongodb/mongodb`'s `MongoDB\Builder` pipeline/expression/stage DSL) after the raw import:

1. `aggregatePriceReportsByDay()` — groups raw price reports per (station, day, fuel), computes `closingPrice`/`lowestPrice`/`highestPrice`, attaches the station (from a per-chunk literal, see "Chunked grouping"), and writes the result via `$merge` into another temp collection (`aggregatedPriceReports_<uniqid>`).
2. `aggregatePriceData()` (`addOpeningPriceAndMergeIntoPrices`) — uses `$setWindowFields` (`$shift`) partitioned by station+fuel to pull the previous day's `closingPrice` in as `openingPrice` (falling back to `boundaryOpeningPrice` for a batch's first day, see below), computes per-price `change`, computes the day's `weightedAveragePrice` (time-weighted by how long each price was in effect over the 24h day), then `$merge`s (`whenMatched: keepExisting`) into the real `DailyPrice` collection.
3. `dailyAggregateRepository->recomputeDailyAggregates()` — groups `DailyPrice` by (day, fuel), computing avg `numChanges`, min/max price, avg weighted price, and approximate percentiles (p50/p90/p95/p99), `$merge`d into `DailyAggregate` (unique on `fuel`+`day`).
4. `updateLatestStationPrices()` — takes the latest `DailyPrice` per (station, fuel) plus the last 30 entries, and `$merge`s them into `Station.latestPrice` / `Station.latestPrices`.

The command supports `--recover` to resume from a leftover temp collection (`priceReportImport_*` or `aggregatedPriceReports_*`) if a previous run crashed mid-pipeline — recovery collections are looked up by name prefix and the user is prompted if more than one exists. 

**Connecting separate imports.** The window function only sees the current batch, so each batch is connected to previously imported data at its two boundaries only (per station+fuel, via `PriceReport::findBatchBoundaries()`), which is O(stations × fuels) indexed lookups instead of touching every `DailyPrice` (an earlier full-collection `$lookup` backfill was too slow and was removed). Order of steps, per worker or in the single-process path:

1. `resolveBoundaryOpeningPrices()` — before the merge, looks up the closing price of the last `DailyPrice` before the batch's first day and stores it on that aggregated document as `boundaryOpeningPrice`.
2. `mergeAggregatedPriceReports()` — as above.
3. `updateOpeningPricesFollowingBatch()` — after the merge, finds the first existing `DailyPrice` after the batch's last day, writes it to the `updatedDays_<uniqid>` temp collection and `$merge`s a corrected `openingPrice`/first `previousPrice`/`change`/`weightedAveragePrice` into it (reuses `getUpdateOpeningPricePipeline()` from the live `reportPrice()` path).
4. `updateLatestStationPrices()` (must come after 3).

Afterwards the parent recomputes `DailyAggregate` only for the affected days (imported days plus days from `updatedDays_*`; `recomputeDailyAggregates(?array $days)`), or for all days if no batch was imported. Known gap: a batch that fills holes in the middle of existing history is not corrected.

**Chunked grouping.** Step 1 (`aggregatePriceReportsByDay`) does not scan the whole import collection per worker. After the import, an index `{station, date}` is created on it, `StationChunker` estimates the number of output groups per station (one index-only pass) and splits the stations of the `Station` collection into chunks of at most ~80k groups, which are put into a temporary `aggregationQueue_<uniqid>` collection. Each chunk runs `$match {station: {$in}}` + `$sort {station, date}` (served by the index, no blocking sort), groups with `$push`/`$top`/`$last` (no `$sortArray`), attaches the chunk's stations from a literal (no `$lookup`) and `$merge`s into the aggregated collection on its unique index `{station._id, fuel, day}` with `whenMatched: replace`, so a chunk can safely be re-run. `ChunkAggregationRunner` claims chunks until the queue is empty and reports a `ChunkResult` per chunk; with `--jobs=N` N `app:import:price-reports-chunk-worker` processes do this and the parent shows a progress bar over stations. Lowest/highest price ties resolve to the earliest report.

**Parallel import.** `--jobs=N` imports files in parallel worker processes (`ParallelFileImporter`), groups in `N` chunk workers (above) and then runs steps 2-4 in `N` workers (`ParallelPriceReportAggregator`, hidden `app:import:price-reports-aggregate-worker`), each handling a slice of the station UUID space (`UuidRange`). `--import-jobs` and `--aggregation-jobs` override it per step (importing is CPU-bound on the client, aggregating on the database server).

There is also a **live single-price ingestion path** independent of CSV import: `DailyPriceRepository::reportPrice(Station, Fuel, price, date)` does an atomic `findAndUpdate` with a pipeline (upsert-and-append the price, recompute lowest/highest/weighted average in one step), then updates `Station.latestPrice(s)` via `updateLatestDailyPriceInStation()`. This is the path to use for adding one price reading outside of bulk CSV import.

## Storage model (MongoDB / Doctrine ODM)

Mapped documents live in `src/Document` (config: `config/packages/doctrine_mongodb.yaml`, mapping dir `src/Document`, custom `point` type → `App\Type\PointType` for GeoJSON).

- **`Station`** (`Document`, `id` = UUID stored natively): `name`, `brand`, `location` (GeoJSON `Point`, `2dsphere` index via `AbstractStation`), embedded `Address`, `favorite: bool`, and embedded `LatestPrice`/`LatestPrices` (current price + rolling last-30 history per fuel, denormalized here to avoid a join for station detail pages).
- **`DailyPrice`** (`Document`): one document per (station, day, fuel) — unique index on `fuel+day+station._id`. Extends `AbstractDailyPrice` (a `MappedSuperclass` shared with `EmbeddedDailyPrice`): `day`, `fuel` (enum), `openingPrice`, `closingPrice`, embedded `lowestPrice`/`highestPrice` (`Price` value objects with `change`/`previousPrice`), embedded `prices` collection (every individual report of that day), `weightedAveragePrice`. Embeds a `PartialStation` (denormalized name/brand/location/address.postCode plus a `ReferenceOne` back to the real `Station._id`) so price documents can be queried/displayed without a join; `PartialStation::refreshData()` re-syncs the denormalized fields from the referenced `Station`. Also has a computed (non-persisted) `aggregate` property backed by a `ReferenceMany` to `DailyAggregate` via `getAggregateForDailyPrice()`.
- **`EmbeddedDailyPrice`** — a deep-cloned, non-referencing snapshot of a `DailyPrice`, embedded into `Station.latestPrice`/`latestPrices` and into `LatestPrices`/`LatestPriceList`/`LatestPrice` (this is how "latest price per station" avoids re-querying `DailyPrice`).
- **`DailyAggregate`** (`Document`, unique index `fuel+day`): market-wide daily stats per fuel — `numChanges`, `lowestPrice`, `highestPrice`, `weightedAveragePrice`, embedded `Percentiles` (p50/p90/p95/p99, used by `DailyAggregate::getPercentile()` to bucket a given price against the day's distribution).
- **`CompoundDailyAggregate`** — a `QueryResultDocument` (not persisted) that reshapes one `DailyAggregate` per fuel for a given day into a single object with `diesel`/`e5`/`e10` fields; produced by `DailyAggregateRepository::getLatestCompoundAggregate()` / `getCompoundDailyAggregates()` for the dashboard.
- **`Price`** (embedded): one price reading, `id` a stringified `ObjectId`, `date`, `price`, optional `previousPrice`/`change`.
- Fuel type is the `App\Fuel` backed enum (`e5`, `e10`, `diesel`), mapped via `enumType:` — there is no separate fuel collection.

Repositories (`src/Repository`) wrap raw `MongoDB\Builder` aggregation pipelines behind typed methods rather than exposing pipeline construction to callers — new aggregation logic should generally be added to `App\Aggregation\PriceReport` and invoked from a repository method, following that pattern.

## Usage / read side

- `App\Selection` — what a page shows: an area (`null` for Germany, two digits for a region, five for a post code), a day and a date range. It lives entirely in the query (`area`, `day`, `from`, `to`), so links, forms and Turbo visits rebuild it with `Selection::toQuery()` instead of keeping state in JS or tabs. `App\Dashboard\AreaAggregates` returns Germany's (`DailyAggregate`) or an area's (`PostCodeDailyAggregate`, combined by station count in `PostCodeDailyAggregateRepository::getCompoundDailyAggregates()`) aggregates in the same `CompoundDailyAggregate` shape; area aggregates only have average/lowest/highest price and station count.
- `IndexController` — homepage: the history of all fuels for the selected area (default Germany) in the `home` turbo-frame, next to a neutral `PostCode:AreaMap` that only selects an area. The map is outside the frame so it keeps its zoom; it navigates the frame (`Turbo.visit(..., {frame})`) and follows the frame's `[data-area]` marker.
- `MapController` — `/map/{fuel}`: price choropleth (`PostCode:PriceMap`) of one fuel for the selected day, with the area's card and chart in the `map-details` frame. Changing the fuel or day is a full page visit, and the map restores the selected area from the URL (`postcode-map` `area` value); changing the area or range only reloads the frame.
- `StationsController` — paginated station list, favorites, filter by post code, station detail page, and a favorite-toggle endpoint. Pagination uses the `App\Doctrine\QueryPaginator` wrapper around Doctrine ODM query builders.
- Twig UX components live in `src/Twig/Component` (`ChartJs`, `DailyAggregateCard`, `Paginator`, `PriceList`, plus `Station`-specific components) and render against the documents above directly (e.g. `DailyPrice`/`EmbeddedDailyPrice` for price lists and charts, `DailyAggregate`/`CompoundDailyAggregate` for the dashboard cards).
- `App\Codec\MetadataCodec`/`MetadataCodecFactory` are used to encode PHP documents (e.g. `Price`, `EmbeddedDailyPrice`) into raw BSON for use as pipeline `let` variables in `findAndUpdate`/aggregation calls — needed because those write paths bypass the normal ODM persist/flush cycle for performance.

## Testing conventions

- Import pipeline tests (`FileImport`) are `KernelTestCase`-based, run the real importer against small fixture CSVs under `FileImport`, then assert on raw documents fetched back from the temp Mongo collection — not on hydrated ODM objects. Follow this pattern (real Mongo interaction, not mocks) for new importer/aggregation tests.
- Test fixture CSVs are hand-crafted to exercise specific edge cases (e.g. sub-threshold prices, unchanged flags) — see the docblock in `PriceReportImporterTest` for the exact intent of each fixture row before adding new ones.
