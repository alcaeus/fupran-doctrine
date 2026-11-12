import * as Turbo from '@hotwired/turbo'

// Loads a URL into a turbo frame and adds it to the history, so that the URL always has the whole selection of the
// page. Turbo 7 could not do this with Turbo.visit(): with a frame it only set the source of the frame and ignored the
// action, so the URL stayed as it was. A link that is clicked does what a click on a link in the page does, including
// the history. Without a frame, the whole page is visited.
export function visit(url, frame = null) {
    if (frame === null) {
        Turbo.visit(url.toString(), { action: 'advance' })

        return
    }

    const link = document.createElement('a')
    link.href = url.toString()
    link.dataset.turboFrame = frame
    link.dataset.turboAction = 'advance'
    link.hidden = true

    document.body.append(link)
    link.click()
    link.remove()
}
