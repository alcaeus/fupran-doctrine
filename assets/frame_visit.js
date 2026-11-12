import * as Turbo from '@hotwired/turbo'

// Loads a URL into a turbo frame and adds it to the history, so that the URL always has the whole selection of the
// page. Turbo.visit() cannot do this: with a frame it only sets the source of the frame and ignores the action, so the
// URL would stay as it was. A link that is clicked does what a click on a link in the page does, including the history.
// Without a frame, the whole page is visited.
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
