import { Controller } from '@hotwired/stimulus'
import { visit } from '../frame_visit.js'

// Opens the page of a day that is clicked in a chart with days on its x axis, keeping the rest of the page's selection.
// The frame is the one to load it into, or _top to visit the whole page.
export default class extends Controller {
    static values = {
        url: String,
        frame: { type: String, default: 'station-day' },
    }

    // The chart is rendered by a child element, which announces it once it has been created
    connect() {
        this.element.addEventListener('chartjs:connect', (event) => {
            const chart = event.detail.chart

            chart.options.onClick = (clickEvent) => {
                const points = chart.getElementsAtEventForMode(clickEvent, 'nearest', { intersect: false }, true)
                if (points.length === 0) {
                    return
                }

                const point = chart.data.datasets[points[0].datasetIndex].data[points[0].index]
                const day = new Date(point.x).toISOString().slice(0, 10)

                const url = new URL(this.urlValue, window.location.origin)
                const current = new URLSearchParams(window.location.search)
                current.forEach((value, key) => url.searchParams.set(key, value))
                url.searchParams.set('day', day)

                visit(url, this.frameValue === '_top' ? null : this.frameValue)
            }
            chart.options.onHover = (hoverEvent, elements) => {
                hoverEvent.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default'
            }
            chart.update()
        })
    }
}
