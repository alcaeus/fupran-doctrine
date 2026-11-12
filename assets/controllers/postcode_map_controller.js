import { Controller } from '@hotwired/stimulus'
import { visit } from '../frame_visit.js'
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

// Maps that only select an area have no prices
function label(area, price) {
    return price === undefined ? area : `${area}: ${priceFormat.format(price)}`
}

// Enhances a Symfony UX map that draws the regions of Germany as polygons:
//
// - Colours the regions with the colour chosen on the server.
// - Clicking a region or post code zooms to it and loads the page with it as the area into a turbo frame next to the
//   map, which shows its prices and history. The links in that frame lead back to the region or to Germany, which the
//   map follows. The area is part of the URL, so the map starts with the area that the page was requested with.
// - From a zoom level on, replaces the regions with the post codes in view. Drawing all 8,000 post code areas at once
//   is far too slow, so they are loaded from the server for the visible part of the map only, whenever it moves.
//   Below that zoom level, a selected region shows its own post codes. Without prices (and a scale), the areas are
//   only a way to select one, and no stations are shown.
// - From a higher zoom level on, also shows markers for the stations in view, with their price and a link to the
//   station. Below it, only a selected post code shows its stations.
export default class extends Controller {
    static values = {
        url: String,
        regionUrl: String,
        stationsUrl: String,
        areaStationsUrl: String,
        stationUrl: String,
        selectUrl: String,
        selectFrame: String,
        area: String,
        day: String,
        label: String,
        lowest: Number,
        highest: Number,
        zoom: { type: Number, default: 8 },
        stationZoom: { type: Number, default: 11 },
    }

    map = null
    regions = []
    regionLayers = new Map()
    postCodes = L.layerGroup()
    stations = L.layerGroup()
    stationsRequest = null
    stationMarkers = new Map()
    // What the station layer shows, to not load the stations of a post code again when the map moves
    shownStations = null
    request = null
    fitted = false
    selectedRegion = null
    selectedPostCode = null
    postCodeLayers = new Map()
    // What the post code layer shows, to not load the post codes of a region again when the map moves
    shownRegion = null
    // A post code to select once the post codes of its region are loaded
    pendingPostCode = null

    style({ detail: { definition } }) {
        definition.bridgeOptions = areaStyle(definition.extra.fillColor)
    }

    decorate({ detail: { polygon, definition } }) {
        const { region, price } = definition.extra

        polygon.bindTooltip(label(`${region}xxx`, price), { sticky: true })
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
        this.#restoreArea()

        // The map of a fuel is drawn while its tab is hidden, where it has no size. Fit once it is shown.
        this.observer = new ResizeObserver(() => {
            map.invalidateSize()
            if (!this.fitted) {
                this.#fitToRegions()
            }
        })
        this.observer.observe(this.element)

        // The frame leads back to the region or to Germany with links, which the map has to follow
        this.frame = document.getElementById(this.selectFrameValue)
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
        this.stationsRequest?.abort()
        this.frame?.removeEventListener('turbo:frame-load', this.onFrameLoad)
    }

    #fitToRegions() {
        if (this.element.offsetWidth > 0 && this.regions.length > 0) {
            this.fitted = true
            this.map.fitBounds(L.featureGroup(this.regions).getBounds())
        }
    }

    #restoreArea() {
        if (this.areaValue.length === 2) {
            this.#showRegion(this.areaValue)
        } else if (this.areaValue.length === 5) {
            this.pendingPostCode = this.areaValue
            this.#showRegion(this.areaValue.slice(0, 2))
        }
    }

    #selectRegion(region) {
        this.#select(region)
        this.#showRegion(region)
    }

    #selectPostCode(postCode) {
        const layer = this.postCodeLayers.get(postCode)
        if (!layer) {
            return
        }

        this.#select(postCode)
        this.selectedPostCode = postCode
        // Below the zoom level for loading post codes by viewport, its region is shown
        this.selectedRegion = postCode.slice(0, 2)
        this.#highlightSelectedPostCode()

        this.map.fitBounds(layer.getBounds())
        // The map does not move if it already shows the post code
        this.#update()
    }

    // Adds the area to the URL, which keeps the day and the range, and loads the page into the frame
    #select(area) {
        const url = new URL(this.selectUrlValue, window.location.origin)
        new URLSearchParams(window.location.search).forEach((value, key) => url.searchParams.set(key, value))
        url.searchParams.set('area', area)

        visit(url, this.selectFrameValue)
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

    #updateStations() {
        if (!this.hasStationsUrlValue) {
            return
        }

        if (this.map.getZoom() >= this.stationZoomValue) {
            this.#loadStations(this.areaStationsUrlValue, this.#boundsQuery(), 'area')
        } else if (this.selectedPostCode !== null) {
            if (this.shownStations !== this.selectedPostCode) {
                this.#loadStations(this.stationsUrlValue.replace(/00000$/, this.selectedPostCode), { day: this.dayValue }, this.selectedPostCode)
            }
        } else {
            this.#clearStations()
        }
    }

    #clearStations() {
        this.stationsRequest?.abort()
        this.shownStations = null
        this.stations.clearLayers()
        this.stationMarkers.clear()
    }

    async #loadStations(url, query, shown) {
        this.stationsRequest?.abort()
        this.stationsRequest = new AbortController()

        let prices
        try {
            const response = await fetch(`${url}?${new URLSearchParams(query)}`, { signal: this.stationsRequest.signal })
            if (!response.ok) {
                throw new Error(`Failed to load stations: ${response.status}`)
            }
            prices = await response.json()
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error)
            }

            return
        }

        // Markers of stations that are still in view stay, so they do not flicker when the map moves
        const ids = new Set(prices.map(({ id }) => id))
        for (const [id, marker] of this.stationMarkers) {
            if (!ids.has(id)) {
                marker.remove()
                this.stationMarkers.delete(id)
            }
        }

        this.shownStations = shown
        for (const station of prices) {
            if (!this.stationMarkers.has(station.id)) {
                this.stationMarkers.set(station.id, this.#createStationMarker(station).addTo(this.stations))
            }
        }
        this.stations.addTo(this.map)
    }

    #createStationMarker({ id, name, brand, latitude, longitude, price }) {
        // Built from DOM nodes as station names are not trusted to be free of markup
        const popup = document.createElement('div')
        const title = document.createElement('strong')
        title.textContent = brand ? `${name} (${brand})` : name
        const link = document.createElement('a')
        link.href = this.stationUrlValue.replace(/0{8}-0{4}-0{4}-0{4}-0{12}$/, id)
        link.textContent = 'Station details'
        popup.append(title, document.createElement('br'), `${priceFormat.format(price)} `, document.createElement('br'), link)

        return L.marker([latitude, longitude], { title: name }).bindPopup(popup)
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
        this.#updateStations()

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
        return { ...this.#scaleQuery(), ...this.#boundsQuery() }
    }

    #boundsQuery() {
        const bounds = this.map.getBounds()

        return {
            day: this.dayValue,
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
                .bindTooltip(label(area.postCode, area.price), { sticky: true })
                .on('click', () => this.#selectPostCode(area.postCode))
                .addTo(this.postCodes)
            this.postCodeLayers.set(area.postCode, layer)
        }
        this.postCodes.addTo(this.map)

        if (this.pendingPostCode !== null) {
            const postCode = this.pendingPostCode
            this.pendingPostCode = null
            this.#restorePostCode(postCode)
        }
    }

    // Selects a post code without loading the frame, as the page was requested with it
    #restorePostCode(postCode) {
        const layer = this.postCodeLayers.get(postCode)
        if (!layer) {
            return
        }

        this.selectedPostCode = postCode
        this.#highlightSelectedPostCode()
        this.map.fitBounds(layer.getBounds())
        this.#update()
    }
}
