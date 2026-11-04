import { Controller } from '@hotwired/stimulus'

// Re-fits a Symfony UX map to its markers with padding. UX Map's own fitBoundsToMarkers() has no padding option, so
// marker icons (which extend above their coordinates) at the top edge of the extent are cut off.
export default class extends Controller {
    static values = { padding: { type: Number, default: 20 } }

    fit(event) {
        const { map, markers } = event.detail
        if (markers.length === 0) {
            return
        }

        const bounds = markers.map((marker) => marker.getLatLng())
        const padding = this.paddingValue

        // Extra room on top for the pin icon, which is drawn above its anchor point
        map.fitBounds(bounds, {
            paddingTopLeft: [padding, padding + 40],
            paddingBottomRight: [padding, padding],
        })
    }
}
