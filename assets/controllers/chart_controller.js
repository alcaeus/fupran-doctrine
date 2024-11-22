import { Controller } from '@hotwired/stimulus'
import { UUID } from 'bson'
import ChartsEmbedSDK from '@mongodb-js/charts-embed-dom'

export default class extends Controller {
    static values = {
        url: String,
        chart: String,
        station: String,
        fuel: String,
        day: Number,
    }

    connect() {
        (new ChartsEmbedSDK())
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
            .render(this.element)
    }

    #getFilter() {
        let filter = {}

        if (this.stationValue) {
            filter['station._id'] = UUID.createFromHexString(this.stationValue)
        }

        if (this.fuelValue) {
            filter.fuel = this.fuelValue
        }

        if (this.dayValue) {
            filter.day = new Date(this.dayValue * 1000)
        }

        return filter
    }
}
