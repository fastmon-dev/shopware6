/**
 * One message between the plugin's own configuration panels.
 *
 * The connection panel and the collection panel are two separate `<component>` entries in
 * config.xml. Shopware renders them as siblings with nothing between them, so linking an
 * application in the first one left the second one showing "no application linked" until
 * the merchant reloaded the page by hand.
 *
 * A module-level emitter is enough for that, and is the smallest thing that is: both
 * panels live in this bundle, an event carries no payload because every listener reads
 * its own state from the admin API anyway, and nothing outside the plugin can subscribe.
 * A Pinia store would be the same thing with a lifecycle to get wrong.
 *
 * Two signals rather than one, because the traffic goes both ways and nobody should have
 * to react to their own announcement:
 *
 *   connection  the linked application changed. The collection card reloads, because what
 *               it can offer depends on there being one.
 *   storefront  something a rendered page carries changed. The connection panel reloads,
 *               because the notice about a stale page cache lives up there.
 */

const listeners = { connection: new Set(), storefront: new Set() };

function emit(topic) {
    listeners[topic].forEach((listener) => {
        try {
            listener();
        } catch (error) {
            // One panel failing to react must not stop the next one from being told.
            console.error('fastmon: a panel failed to react to a ' + topic + ' change', error);
        }
    });
}

function subscribe(topic, listener) {
    listeners[topic].add(listener);

    return () => listeners[topic].delete(listener);
}

/** Connected, disconnected, or a different application linked. */
export function connectionChanged() {
    emit('connection');
}

/**
 * @param {() => void} listener
 * @returns {() => void} call it to unsubscribe, which a panel does when it goes away
 */
export function onConnectionChanged(listener) {
    return subscribe('connection', listener);
}

/** A value the storefront renders changed, so its cached pages are behind. */
export function storefrontChanged() {
    emit('storefront');
}

/**
 * @param {() => void} listener
 * @returns {() => void} call it to unsubscribe
 */
export function onStorefrontChanged(listener) {
    return subscribe('storefront', listener);
}
