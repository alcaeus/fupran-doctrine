# Fupran

A Symfony 8.1 / PHP 8.4 application that ingests German fuel price data (the
MTS-K data as published by [Tankerkönig](https://creativecommons.tankerkoenig.de/))
into MongoDB using Doctrine ODM. Prices are pre-aggregated into per-station daily
prices and market-wide daily aggregates, and served through a small web UI:

* a dashboard with the latest daily aggregates and a 60-day price history chart
* a station list with favorites, search (MongoDB Atlas Search) and post code filter
* a station detail page with the price history for E5, E10 and Diesel

## Requirements

* PHP 8.4 or newer with the `ext-mongodb` (^2.2), `ctype` and `iconv` extensions
* [Composer](https://getcomposer.org/)
* MongoDB 8.x. The station search uses an Atlas Search index, so the search page
  only works against MongoDB Atlas or a deployment with `mongot` (e.g. the Atlas
  local deployment). Everything else works with a plain `mongod`.
* Enough memory and disk space for the price data: a full year of reports is
  several GB of CSV

`compose.yaml` starts a local MongoDB for development (`docker compose up -d`).

## Configuration

The application reads these environment variables (set them in `.env.local` or
`.env.prod.local`, or in the real environment):

| Variable      | Description                                      |
|---------------|--------------------------------------------------|
| `MONGODB_URL` | MongoDB connection string                        |
| `MONGODB_DB`  | Name of the database to use                      |
| `APP_ENV`     | Set to `prod` for production                     |
| `APP_SECRET`  | Random secret for your instance                  |

## Installation

```shell
composer install --no-dev --optimize-autoloader
bin/console asset-map:compile
bin/console cache:clear
```

## Getting the data

The application is fed with the CSV exports from the Tankerkönig open data
project. Refer to the
[Tankerkönig open data documentation](https://creativecommons.tankerkoenig.de/)
for how to obtain the data, its license (CC BY 4.0) and the exact file format.
The data is published as a git repository, with one file per day:

```
stations/YYYY/MM/YYYY-MM-DD-stations.csv
prices/YYYY/MM/YYYY-MM-DD-prices.csv
```

Clone or download the repository (or only the days you are interested in) to a
location the application can read. Small hand-crafted examples that show the
expected columns are in `fixtures/`.

## Bootstrapping the database

Create the collections, indexes and the Atlas Search index:

```shell
bin/console doctrine:mongodb:schema:create
```

This creates, among others, the unique indexes on `DailyPrice` (`fuel`, `day`,
`station._id`) and `DailyAggregate` (`fuel`, `day`), the `2dsphere` index on
station locations and the `station` search index. The unique indexes are needed
for the import to be idempotent, so create the schema **before** importing any
data. If you only want to (re)create the indexes later, use
`doctrine:mongodb:schema:update`.

### Upgrading: station visibility

Stations are only listed once they have prices (`Station.visible`, set whenever
latest prices are written). After updating, run `doctrine:mongodb:schema:update`
and backfill the flag for stations that already have prices:

```js
db.Station.updateMany(
  { $or: [
    { "latestPrices.diesel.0": { $exists: true } },
    { "latestPrices.e5.0": { $exists: true } },
    { "latestPrices.e10.0": { $exists: true } },
  ] },
  { $set: { visible: true } }
);
```

## Loading data

### 1. Stations

Import the stations first; price reports are matched to existing stations.

```shell
bin/console app:import:stations path/to/tankerkoenig-data/stations
```

Each argument may be a file or a directory (every `*.csv` below it is imported),
and you can pass multiple arguments. Stations are upserted by UUID, so it is safe
to re-import later days to pick up new or changed stations. Use `--clear` to
remove all stations before importing.

### 2. Price reports

```shell
bin/console app:import:price-reports path/to/tankerkoenig-data/prices
```

The import runs in several stages:

1. Price changes are read from the CSVs into a temporary collection
   (`priceReportImport_*`). Rows without a price change and prices below 0.50 €
   are skipped.
2. They are grouped into one `DailyPrice` document per station, day and fuel,
   including opening/closing/lowest/highest and time-weighted average price.
3. The first day of the import is connected to previously imported days: it takes
   its opening price from the last earlier day, and the first later day (if any)
   is updated with the import's closing price.
4. The market-wide `DailyAggregate` documents are recomputed for the affected days.
5. The latest prices embedded in each `Station` are updated.

Notes:

* Days can be imported in any order and in separate runs: opening prices are
  taken from the previous day's closing price, and days imported later are
  connected to the data that is already there. The one exception is a run that
  fills a gap in the middle of a station's existing history, which is not
  corrected.
* Use `--jobs=N` to import and aggregate with `N` parallel worker processes.
  The two steps can be tuned separately with `--import-jobs=N` and
  `--aggregation-jobs=N`, which override `--jobs`. Importing parses the files
  in worker processes on the machine running the command, whereas aggregating
  runs on the database server, so the latter can usually use more workers.
* Imports of large amounts of data take a long time. If a run crashes midway,
  resume with `--recover`; the command finds the leftover temporary collection
  and asks you which one to use if there are several.
* `--clear` removes all existing prices before importing.
* To rebuild the daily aggregates without importing anything, run
  `bin/console app:data:compute:daily-aggregates`.

### Keeping the data up to date

Run both imports for the new day's files on a schedule (e.g. a daily cron job
after pulling the Tankerkönig repository), stations first.

## Development

```shell
symfony php vendor/bin/phpunit   # tests (need a running MongoDB, see .env.test)
composer phpstan                 # static analysis
vendor/bin/phpcs                 # coding standard
```
