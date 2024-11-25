import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['fromDay', 'toDay']

    connect() {
        if ((this.hasFromDayTarget && !this.hasToDayTarget) || (!this.hasFromDayTarget && this.hasToDayTarget)) {
            console.log('Error: need to specify both fromDay and toDay targets, or none.')
        }
    }

    update() {
        this.dispatch(
            'update',
            {detail: {filter: this.#createFilter()}},
        )
    }

    chartConnected(event) {
        if (!event.detail.updateFilter) {
            return
        }

        event.detail.updateFilter(this.#createFilter())
    }

    #createFilter() {
        let filter = {}

        if (this.hasFromDayTarget) {
            filter.day = {}

            filter.day['$gte'] = new Date(this.fromDayTarget.value)
            filter.day['$lte'] = new Date(this.toDayTarget.value)
        }

        return filter
    }
}
