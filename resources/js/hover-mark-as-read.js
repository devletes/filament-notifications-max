/**
 * Hover-to-mark-as-read for bell-panel notifications.
 *
 * Listens for hover on `.fi-no-notification.fi-inline` items (= bell-panel
 * notifications; toasts don't carry the `fi-inline` class). When the cursor
 * stays over an UNREAD item for `delayMs` milliseconds, this calls Filament's
 * existing Alpine `markAsRead()` method on the notification component, which
 * dispatches `markedNotificationAsRead` — handled natively by Filament's base
 * DatabaseNotifications Livewire component to update the row's `read_at`.
 *
 * No overrides, no copies of vendor JS. Pure consumer of public Filament
 * surfaces (Alpine component, CSS classes, base event handler).
 *
 * Configuration is delivered via Filament's official `registerScriptData()`
 * channel — read here from `window.filamentData.notificationsMax.hoverMarkAsReadDelay`.
 * If absent or non-numeric, the listeners no-op.
 */
(function () {
    'use strict'

    const ITEM_SELECTOR = '.fi-no-notification.fi-inline'
    const UNREAD_WRAPPER_SELECTOR = '.fi-no-notification-unread-ctn'

    // One pending timer per element. WeakMap so detached items get GC'd.
    const timers = new WeakMap()

    function getDelayMs() {
        const data = window.filamentData?.notificationsMax
        const value = data?.hoverMarkAsReadDelay

        return typeof value === 'number' && value > 0 ? value : null
    }

    function isUnread(root) {
        return root.closest(UNREAD_WRAPPER_SELECTOR) !== null
    }

    function startTimer(root) {
        if (timers.has(root)) return
        if (! isUnread(root)) return

        const delay = getDelayMs()
        if (delay === null) return

        const timer = setTimeout(() => {
            const data = window.Alpine?.$data?.(root)
            if (data && typeof data.markAsRead === 'function') {
                data.markAsRead()
            }
            timers.delete(root)
        }, delay)

        timers.set(root, timer)
    }

    function cancelTimer(root) {
        const timer = timers.get(root)
        if (timer) {
            clearTimeout(timer)
            timers.delete(root)
        }
    }

    // mouseover/mouseout bubble (mouseenter/mouseleave do not), so we can
    // delegate from `document`. The `relatedTarget` check filters out moves
    // between child elements within the same notification.
    document.addEventListener('mouseover', (e) => {
        const root = e.target.closest?.(ITEM_SELECTOR)
        if (! root) return
        if (root.contains(e.relatedTarget)) return
        startTimer(root)
    })

    document.addEventListener('mouseout', (e) => {
        const root = e.target.closest?.(ITEM_SELECTOR)
        if (! root) return
        if (root.contains(e.relatedTarget)) return
        cancelTimer(root)
    })
})()
