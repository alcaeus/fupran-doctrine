import { Controller } from '@hotwired/stimulus'
import * as L from 'leaflet'

const priceFormat = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
})

// The areas are partly transparent so the streets and place names of the base map stay readable underneath
function areaStyle(fillColor, selected = false) {
    return { color: selected ? '#000' : '#555', weight: selected ? 3 : 1, fillColor, fillOpacity: 0.4 }
}

// Enhances a Symfony UX map that draws the regions of Germany as polygons:
//
// - Colours the regions with the colour chosen on the server.
// - Clicking a region or post code zooms to it and shows its prices in the card (a turbo frame) next to the map. The
//   links in that card lead back to the region or to the prices of Germany, which the map follows.
// - From a zoom level on, replaces the regions with the post codes in view. Drawing all 8,000 post code areas at once
//   is far too slow, so they are loaded from the server for the visible part of the map only, whenever it moves.
//   Below that zoom level, a selected region shows its own post codes.
export default class extends Controller {
    static values = {
        url: String,
        regionUrl: String,
        cardUrl: String,
        cardFrame: String,
        day: String,
        label: String,
        lowest: Number,
        highest: Number,
        zoom: { type: Number, default: 8 },
    }

    map = null
    regions = []
    regionLayers = new Map()
    postCodes = L.layerGroup()
    request = null
    fitted = false
    selectedRegion = null
    selectedPostCode = null
    postCodeLayers = new Map()
    // What the post code layer shows, to not load the post codes of a region again when the map moves
    shownRegion = null

    style({ detail: { definition } }) {
        definition.bridgeOptions = areaStyle(definition.extra.fillColor)
    }

    decorate({ detail: { polygon, definition } }) {
        const { region, price } = definition.extra

        polygon.bindTooltip(`${region}xxx: ${priceFormat.format(price)}`, { sticky: true })
        polygon.on('click', () => this.#selectRegion(region))

        if (!this.regionLayers.has(region)) {
            this.regionLayers.set(region, [])
        }
        this.regionLayers.get(region).push(polygon)
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

        // The card leads back to the region or to Germany with links, which the map has to follow
        this.frame = document.getElementById(this.cardFrameValue)
        this.onFrameLoad = () => {
            const area = this.frame.querySelector('[data-area]')?.dataset.area ?? null
            if (area === (this.selectedPostCode ?? this.selectedRegion)) {
                return
            }

            if (area === null) {
                this.#showOverview()
            } else if (area.length === 2) {
                this.#showRegion(area)
            }
        }
        this.frame?.addEventListener('turbo:frame-load', this.onFrameLoad)
    }

    disconnect() {
        this.observer?.disconnect()
        this.request?.abort()
        this.frame?.removeEventListener('turbo:frame-load', this.onFrameLoad)
    }

    #fitToRegions() {
        if (this.element.offsetWidth > 0 && this.regions.length > 0) {
            this.fitted = true
            this.map.fitBounds(L.featureGroup(this.regions).getBounds())
        }
    }

    #selectRegion(region) {
        this.#loadCard({ region })
        this.#showRegion(region)
    }

    #selectPostCode(postCode) {
        const layer = this.postCodeLayers.get(postCode)
        if (!layer) {
            return
        }

        this.#loadCard({ postCode })
        this.selectedPostCode = postCode
        // Below the zoom level for loading post codes by viewport, its region is shown
        this.selectedRegion = postCode.slice(0, 2)
        this.#highlightSelectedPostCode()

        this.map.fitBounds(layer.getBounds())
        // The map does not move if it already shows the post code
        this.#update()
    }

    #loadCard(query) {
        if (this.frame) {
            this.frame.src = `${this.cardUrlValue}?${new URLSearchParams({ day: this.dayValue, ...query })}`
        }
    }

    #showRegion(region) {
        this.selectedRegion = region
        this.selectedPostCode = null
        this.#highlightSelectedPostCode()

        this.map.fitBounds(L.featureGroup(this.regionLayers.get(region)).getBounds())
        // The map does not move if it already shows the region
        this.#update()
    }

    #showOverview() {
        this.selectedRegion = null
        this.selectedPostCode = null
        this.#fitToRegions()
        this.#update()
    }

    #highlightSelectedPostCode() {
        for (const [postCode, layer] of this.postCodeLayers) {
            layer.setStyle(areaStyle(layer.options.fillColor, postCode === this.selectedPostCode))
            if (postCode === this.selectedPostCode) {
                layer.bringToFront()
            }
        }
    }

    #update() {
        if (this.map.getZoom() >= this.zoomValue) {
            this.#loadPostCodes(this.urlValue, this.#viewportQuery(), null)
        } else if (this.selectedRegion !== null) {
            if (this.shownRegion !== this.selectedRegion) {
                this.#loadPostCodes(this.regionUrlValue.replace(/00$/, this.selectedRegion), this.#scaleQuery(), this.selectedRegion)
            }
        } else {
            this.request?.abort()
            this.shownRegion = null
            this.#showRegions(true)
            this.postCodes.clearLayers()
            this.postCodeLayers.clear()
        }
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

    #scaleQuery() {
        return { day: this.dayValue, lowest: this.lowestValue, highest: this.highestValue }
    }

    #viewportQuery() {
        const bounds = this.map.getBounds()

        return {
            ...this.#scaleQuery(),
            south: bounds.getSouth(),
            west: bounds.getWest(),
            north: bounds.getNorth(),
            east: bounds.getEast(),
        }
    }

    async #loadPostCodes(url, query, region) {
        this.request?.abort()
        this.request = new AbortController()

        let areas
        try {
            const response = await fetch(`${url}?${new URLSearchParams(query)}`, { signal: this.request.signal })
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
        this.postCodeLayers.clear()
        this.shownRegion = region
        for (const area of areas) {
            const layer = L.polygon(area.polygons, areaStyle(area.color, area.postCode === this.selectedPostCode))
                .bindTooltip(`${area.postCode}: ${priceFormat.format(area.price)}`, { sticky: true })
                .on('click', () => this.#selectPostCode(area.postCode))
                .addTo(this.postCodes)
            this.postCodeLayers.set(area.postCode, layer)
        }
        this.postCodes.addTo(this.map)
    }
}
