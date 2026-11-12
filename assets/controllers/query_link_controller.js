import { Controller } from '@hotwired/stimulus'

// Makes a link keep the query of the current URL. Parts of a page can change what is selected without loading the link,
// so the URL it was rendered with can be out of date.
export default class extends Controller {
    keep() {
        this.element.search = window.location.search
    }
}
