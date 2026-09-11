/**
 * One message between the plugin's own configuration panels.
 *
 * The connection panel and the collection panel are two separate `<component>` entries in
 * config.xml, rendered as siblings with nothing between them, so linking an application in
 * the first one left the second showing "no application linked" until the merchant
 * reloaded the page by hand.
 *
 * A module-level emitter is the smallest thing that fixes that: both panels live in this
 * bundle, the event carries no payload because every listener reads its own state from the
 * admin API anyway, and nothing outside the plugin can subscribe.
 */

const listeners = new Set();

/** Connected, disconnected, or a different application linked. */
export function connectionChanged() {
    listeners.forEach((listener) => {
        try {
            listener();
        } catch (error) {
            // One panel failing to react must not stop the next one from being told.
            console.error('fastmon: a panel failed to react to a connection change', error);
        }
    });
}

/**
 * @param {() => void} listener
 * @returns {() => void} call it to unsubscribe, which a panel does when it goes away
 */
export function onConnectionChanged(listener) {
    listeners.add(listener);

    return () => listeners.delete(listener);
}
