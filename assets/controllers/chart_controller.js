import { Controller } from '@hotwired/stimulus'
import { UUID } from 'bson'
import ChartsEmbedSDK from '@mongodb-js/charts-embed-dom'

export default class extends Controller {
    static targets = ['chart', 'fromDay', 'toDay']

    static values = {
        tabbed: Boolean,
        url: String,
        chart: String,
        station: String,
        fuel: String,
        day: Number,
    }

    rendered = false

    connect() {
        this.chart = (new ChartsEmbedSDK())
            .createChart({
                baseUrl: this.urlValue,
                chartId: this.chartValue,
                preFilter: this.#getFilter(),
                maxDataAge: 86400 * 7, // 1 week
                autoRefresh: false,
                showAttribution: false,
                renderingSpec: {
                    version: 1,
                    title: '',
                    description: '',
                },
            })

        // TODO: Re-enable once this is properly working. Right now, show.bs.tab event isn't fired
        // If this is a tabbed chart, add an event listener to only render the chart when it's shown
        if (false && this.element.classList.contains('tab-pane')) {
            // Render the chart in the currently active tab
            if (this.element.classList.contains('show')) {
                this.#render()
            } else {
                // Register an event listener for all hidden tabs to render charts on-demand
                this.element.addEventListener('show.bs.tab', () => {
                    this.#render()
                    this.element.removeEventListener('show.bs.tab')
                })
            }
        } else {
            this.#render()
        }
    }

    updateChartFilter(event) {
        this.chart.setPreFilter(this.#getFilter())
    }

    #render() {
        if (this.rendered) {
            return;
        }

        this.chart.render(this.chartTarget)

        this.rendered = true
    }

    #getFilter() {
        let filter = {}

        if (this.stationValue) {
            filter['station._id'] = UUID.createFromHexString(this.stationValue)
        }

        if (this.fuelValue) {
            filter.fuel = this.fuelValue
        }

        if (this.hasFromDayTarget || this.hasToDayTarget) {
            filter.day = {}

            if (this.hasFromDayTarget) {
                filter.day['$gte'] = new Date(this.fromDayTarget.value)
            }
            if (this.hasToDayTarget) {
                filter.day['$lte'] = new Date(this.toDayTarget.value)
            }
        } else if (this.dayValue) {
            filter.day = new Date(this.dayValue * 1000)
        }

        return filter
    }
}
