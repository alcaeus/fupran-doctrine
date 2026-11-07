#!/usr/bin/env bash
#
# Rebuilds the post code shapes in assets/data from a release of https://github.com/yetzt/postleitzahlen
# (OpenStreetMap data, ODbL):
#
# - postcodes.topojson: the post code areas. The release is 180 MB uncompressed, so small triangles (below the 5% area
#   quantile) and small detached rings are removed, the coordinates are quantized, and the geometry ids are dropped.
#   Only the post code (`properties.postcode`) of each area is kept.
# - postcode-regions.topojson: the areas merged by the first two digits of their post code, with the region (the two
#   digits) as the `id` of each shape. Only ever drawn as a whole, so it is simplified much more: triangles below a
#   fixed area (about one pixel on a map of Germany that is 600 pixels wide) are removed. A quantile cannot be used, as
#   it would be relative to the unsimplified shapes.
#
# Usage: bin/build-postcode-topology.sh [release, e.g. 2026.02]
set -euo pipefail

RELEASE="${1:-2026.02}"
TARGET_DIR="$(cd "$(dirname "$0")/.." && pwd)/assets/data"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$TARGET_DIR"

curl --fail --silent --show-error --location \
    "https://github.com/yetzt/postleitzahlen/releases/download/${RELEASE}/postleitzahlen.topojson.br" \
    | node -e "process.stdout.write(require('zlib').brotliDecompressSync(require('fs').readFileSync(0)))" \
    > "$WORK_DIR/release.topojson"

# Post code areas
npx --yes --package topojson-simplify@3.0.3 toposimplify --planar-quantile 0.05 --filter-detached < "$WORK_DIR/release.topojson" \
    | npx --yes --package topojson-client@3.1.0 topoquantize 100000 \
    | node -e "
        const topology = JSON.parse(require('fs').readFileSync(0, 'utf8'));
        for (const geometry of topology.objects.postleitzahlen.geometries) {
            delete geometry.id;
            delete geometry.properties.rel;
        }
        process.stdout.write(JSON.stringify(topology));
    " > "$WORK_DIR/postcodes.topojson"

# Regions. Merging keeps the arcs of all post code areas, so the result is converted to GeoJSON and back to drop them.
npx --yes --package topojson-client@3.1.0 topomerge --key 'd.properties.postcode.slice(0, 2)' regions=postleitzahlen < "$WORK_DIR/release.topojson" \
    | npx --yes --package topojson-client@3.1.0 topo2geo regions="$WORK_DIR/regions.geojson"
npx --yes --package topojson-server@3.0.1 geo2topo regions="$WORK_DIR/regions.geojson" \
    | npx --yes --package topojson-simplify@3.0.3 toposimplify --planar-area 0.0001 --filter-detached \
    | npx --yes --package topojson-client@3.1.0 topoquantize 20000 \
    > "$WORK_DIR/postcode-regions.topojson"

mv "$WORK_DIR/postcodes.topojson" "$WORK_DIR/postcode-regions.topojson" "$TARGET_DIR/"

for file in postcodes postcode-regions; do
    echo "$file.topojson: $(wc -c < "$TARGET_DIR/$file.topojson") bytes, $(node -e "
        const topology = JSON.parse(require('fs').readFileSync(process.argv[1], 'utf8'));
        process.stdout.write(String(topology.arcs.reduce((points, arc) => points + arc.length, 0)));
    " "$TARGET_DIR/$file.topojson") points"
done
