import { Controller } from '@hotwired/stimulus'
import { Chart } from 'chart.js'
import { ChoroplethController, ColorScale, GeoFeature, ProjectionScale } from 'chartjs-chart-geo'
import { geoArea } from 'd3-geo'
import { feature } from 'topojson-client'

Chart.register(ChoroplethController, GeoFeature, ColorScale, ProjectionScale)

const priceFormat = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
})

// d3-geo works on a sphere, where a ring wound the wrong way is the whole sphere except the area it encloses. The
// shapes are wound the other way (like GeoJSON, as opposed to d3), which would fill the entire map.
function rewind(area) {
    if (geoArea(area) <= 2 * Math.PI) {
        return area
    }

    const reverse = (rings) => rings.map((ring) => [...ring].reverse())
    const { geometry } = area

    return {
        ...area,
        geometry: {
            ...geometry,
            coordinates: geometry.type === 'Polygon' ? reverse(geometry.coordinates) : geometry.coordinates.map(reverse),
        },
    }
}

// The charts of all fuels use the same shapes, so each file is only fetched once
const topologies = new Map()

function fetchTopology(url) {
    if (!topologies.has(url)) {
        topologies.set(url, fetch(url).then((response) => {
            if (!response.ok) {
                throw new Error(`Failed to load ${url}: ${response.status}`)
            }

            return response.json()
        }).catch((error) => {
            // Allow trying again
            topologies.delete(url)

            throw error
        }))
    }

    return topologies.get(url)
}

// Colours regions of post codes (by their first two digits) by price. Clicking a region shows its post code areas.
//
// Drawing all 8,000 post code areas at once is far too slow, so only the few dozen areas of a single region are ever
// drawn. All prices are part of the page, so the shapes are the only thing that is loaded. The shapes of the post code
// areas are large and only loaded once a region is opened.
export default class extends Controller {
    static targets = ['canvas', 'back', 'title']

    static values = {
        regionsUrl: String,
        postCodesUrl: String,
        regionPrices: Object,
        prices: Object,
        label: { type: String, default: 'Weighted average price' },
    }

    chart = null
    regions = null
    postCodes = null

    async connect() {
        this.regions = await this.#fetchAreas(this.regionsUrlValue, 'regions', (area) => String(area.id))

        // The element may have been removed (e.g. by Turbo) while loading
        if (this.element.isConnected) {
            this.showRegions()
        }
    }

    disconnect() {
        this.#destroyChart()
    }

    showRegions() {
        this.backTarget.hidden = true
        this.titleTarget.textContent = 'All regions'
        this.#render(
            this.regions.filter((area) => this.regionPricesValue[area.label] !== undefined),
            this.regionPricesValue,
            (label) => `${label}xxx`,
            (label) => this.#showRegion(label),
        )
    }

    async #showRegion(region) {
        // Loaded on demand, as it is much larger than the regions
        this.postCodes ??= await this.#fetchAreas(this.postCodesUrlValue, 'postleitzahlen', (area) => area.properties.postcode)

        if (!this.element.isConnected) {
            return
        }

        this.backTarget.hidden = false
        this.titleTarget.textContent = `Post codes ${region}xxx`
        this.#render(
            this.postCodes.filter((area) => area.label.startsWith(region) && this.pricesValue[area.label] !== undefined),
            this.pricesValue,
            (label) => label,
        )
    }

    // Areas without a price are not passed in, as the colour scale cannot handle missing values
    #render(areas, prices, formatLabel, onSelect = null) {
        this.#destroyChart()

        // Done for the areas that are shown only, as it needs to look at every point of an area
        for (const area of areas) {
            if (!area.rewound) {
                area.feature = rewind(area.feature)
                area.rewound = true
            }
        }

        this.chart = new Chart(this.canvasTarget, {
            type: 'choropleth',
            data: {
                labels: areas.map((area) => area.label),
                datasets: [{
                    label: this.labelValue,
                    data: areas.map((area) => ({ feature: area.feature, value: prices[area.label] })),
                    // The map is scaled to fit its outline, which is the entire globe unless set. It is not drawn,
                    // as that is turned off with showOutline.
                    outline: areas.map((area) => area.feature),
                }],
            },
            options: {
                // The map is redrawn completely for every frame, which would be noticeable even with few areas
                animation: false,
                showOutline: false,
                maintainAspectRatio: false,
                onClick: onSelect === null ? undefined : (event, elements) => {
                    if (elements.length > 0) {
                        onSelect(areas[elements[0].index].label)
                    }
                },
                onHover: onSelect === null ? undefined : (event, elements) => {
                    event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default'
                },
                scales: {
                    projection: { axis: 'x', projection: 'mercator' },
                    color: {
                        axis: 'x',
                        interpolate: 'oranges',
                        quantize: 8,
                        legend: { position: 'bottom-right', align: 'right' },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${formatLabel(areas[context.dataIndex].label)}: ${priceFormat.format(context.raw.value)}`,
                        },
                    },
                },
            },
        })
    }

    #destroyChart() {
        this.chart?.destroy()
        this.chart = null
    }

    async #fetchAreas(url, objectName, getLabel) {
        const topology = await fetchTopology(url)

        return feature(topology, topology.objects[objectName]).features
            .map((area) => ({ feature: area, label: getLabel(area) }))
    }
}
