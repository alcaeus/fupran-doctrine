import { Controller } from '@hotwired/stimulus'
import { UUID } from 'bson'
import ChartsEmbedSDK from '@mongodb-js/charts-embed-dom'

export default class extends Controller {
    static values = {
        station: String,
        fuel: String,
    }

    connect() {
        (new ChartsEmbedSDK())
            .createChart({
                baseUrl: 'https://charts.mongodb.com/charts-fupran-ndvby',
                chartId: '569c271a-1c62-4e7d-97db-06ffcab710ff',
                preFilter: {
                    'station._id': UUID.createFromHexString(this.stationValue),
                    fuel: this.fuelValue,
                }
            })
            .render(this.element)
    }
}
