import { Controller } from '@hotwired/stimulus'
import { UUID } from 'bson'
import ChartsEmbedSDK from '@mongodb-js/charts-embed-dom'

export default class extends Controller {
    static values = {
        url: String,
        chart: String,
        station: String,
        fuel: String,
    }

    connect() {
        (new ChartsEmbedSDK())
            .createChart({
                baseUrl: this.urlValue,
                chartId: this.chartValue,
                preFilter: {
                    'station._id': UUID.createFromHexString(this.stationValue),
                    fuel: this.fuelValue,
                }
            })
            .render(this.element)
    }
}
