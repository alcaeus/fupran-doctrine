import { Controller } from '@hotwired/stimulus'
import * as L from 'leaflet'

const priceFormat = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
})

// The areas are partly transparent so the streets and place names of the base map stay readable underneath
function areaStyle(fillColor) {
    return { color: '#555', weight: 1, fillColor, fillOpacity: 0.4 }
}

// Enhances a Symfony UX map that draws the regions of Germany as polygons:
//
// - Colours the regions with the colour chosen on the server.
// - From a zoom level on, replaces the regions with the post codes in view. Drawing all 8,000 post code areas at once
//   is far too slow, so they are loaded from the server for the visible part of the map only, whenever it moves.
export default class extends Controller {
    static values = {
        url: String,
        day: String,
        label: String,
        lowest: Number,
        highest: Number,
        zoom: { type: Number, default: 8 },
    }

    map = null
    regions = []
    postCodes = L.layerGroup()
    request = null
    fitted = false

    style({ detail: { definition } }) {
        definition.bridgeOptions = areaStyle(definition.extra.fillColor)
    }

    // Not named connect(), which is Stimulus' own callback
    connectMap({ detail: { map, polygons } }) {
        this.map = map
        this.regions = polygons

        this.#fitToRegions()
        map.on('moveend', () => this.#update())

        // The map of a fuel is drawn while its tab is hidden, where it has no size. Fit once it is shown.
        this.observer = new ResizeObserver(() => {
            map.invalidateSize()
            if (!this.fitted) {
                this.#fitToRegions()
            }
        })
        this.observer.observe(this.element)
    }

    disconnect() {
        this.observer?.disconnect()
        this.request?.abort()
    }

    #fitToRegions() {
        if (this.element.offsetWidth > 0 && this.regions.length > 0) {
            this.fitted = true
            this.map.fitBounds(L.featureGroup(this.regions).getBounds())
        }
    }

    #update() {
        if (this.map.getZoom() < this.zoomValue) {
            this.request?.abort()
            this.#showRegions(true)
            this.postCodes.clearLayers()

            return
        }

        this.#loadPostCodes()
    }

    #showRegions(visible) {
        for (const region of this.regions) {
            if (visible) {
                region.addTo(this.map)
            } else {
                region.remove()
            }
        }
    }

    async #loadPostCodes() {
        this.request?.abort()
        this.request = new AbortController()

        const bounds = this.map.getBounds()
        const query = new URLSearchParams({
            day: this.dayValue,
            south: bounds.getSouth(),
            west: bounds.getWest(),
            north: bounds.getNorth(),
            east: bounds.getEast(),
            lowest: this.lowestValue,
            highest: this.highestValue,
        })

        let areas
        try {
            const response = await fetch(`${this.urlValue}?${query}`, { signal: this.request.signal })
            if (!response.ok) {
                throw new Error(`Failed to load post codes: ${response.status}`)
            }
            areas = await response.json()
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error)
            }

            return
        }

        // The regions are only hidden once there is something to replace them with
        this.#showRegions(false)
        this.postCodes.clearLayers()
        for (const area of areas) {
            L.polygon(area.polygons, areaStyle(area.color))
                .bindPopup(`<strong>${area.postCode}</strong><p class="mb-0">${this.labelValue}: ${priceFormat.format(area.price)}</p>`)
                .addTo(this.postCodes)
        }
        this.postCodes.addTo(this.map)
    }
}
