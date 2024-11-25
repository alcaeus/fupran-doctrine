import { Controller } from '@hotwired/stimulus'
import { UUID } from 'bson'
import ChartsEmbedSDK from '@mongodb-js/charts-embed-dom'

export default class extends Controller {
    static targets = ['chart']

    static values = {
        tabbed: Boolean,
        url: String,
        chart: String,
        station: String,
        fuel: String,
        day: Number,
    }

    rendered = false
    filter = null
    isDeferredRender = false

    connect() {
        // Initialize the filter, so that it can then be updated if necessary
        this.#initializeFilter()

        // Send out a connected event that is handled by a chart filter element
        // If there is a listener connected, it will call the closure provided in the event and update our filter
        this.dispatch('connected', {
            detail: {updateFilter: (filter) => this.#storeFilter(filter)}
        })

        // TODO: Re-enable once this is properly working. Right now, show.bs.tab event isn't fired
        // If this is a tabbed chart, add an event listener to only render the chart when it's shown
        if (false && this.element.classList.contains('tab-pane')) {
            // Render the chart in the currently active tab
            if (this.element.classList.contains('show')) {
                this.#render()
            } else {
                // Register an event listener for all hidden tabs to render charts on-demand
                this.isDeferredRender = true
                this.element.addEventListener('show.bs.tab', () => {
                    this.#render()
                    this.element.removeEventListener('show.bs.tab')
                })
            }
        } else {
            this.#render()
        }
    }

    updateFilter(event) {
        this.#storeFilter(event.detail.filter)

        // If no chart has been created, create and render it now
        // If the chart is in a deferred render state, i.e. in a tab that has never been shown, don't render it
        if (this.chart == null) {
            if (! this.isDeferredRender) {
                this.#render()
            }

            return
        }

        // If we get here, then the chart already exists, i.e. it has been rendered
        // Only update the preFilter
        this.chart.setPreFilter(this.#getFilter())
    }

    #storeFilter(filter) {
        for (let key in filter) {
            if (key == 'day' && this.dayValue) {
                continue
            }

            this.filter[key] = filter[key]
        }
    }

    #render() {
        if (this.rendered) {
            return;
        }

        if (this.chart == null) {
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
        }

        this.chart.render(this.chartTarget)

        this.rendered = true
    }

    #getFilter() {
        return this.filter ?? this.#initializeFilter()
    }

    #initializeFilter() {
        this.filter = {}

        if (this.stationValue) {
            this.filter['station._id'] = UUID.createFromHexString(this.stationValue)
        }

        if (this.fuelValue) {
            this.filter.fuel = this.fuelValue
        }

        if (this.dayValue) {
            this.filter.day = new Date(this.dayValue * 1000)
        }

        return this.filter
    }
}
