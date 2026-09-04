/**
 * The browser's half of the connect flow.
 *
 * fastmon sends the merchant back to the administration's own address with `?code=` and
 * `?state=` on it, an exact registered redirect URI that a hash route could never be. So
 * the code arrives on whatever page the administration loads first, usually the dashboard,
 * and has to find its way back to the plugin's configuration page. This file runs on every
 * administration boot and does exactly that on the one boot after a consent screen: take
 * the parameters out of the URL, keep them in `sessionStorage`, and land back on the page
 * the merchant started from, where the connection panel hands them to the shop.
 *
 * `sessionStorage` is the right place for it: per tab, cleared when the tab closes, and
 * what it holds is worthless on its own, because redeeming the code needs the PKCE
 * verifier and that never leaves the server.
 *
 * Nothing happens here unless this shop started a connection in this tab, so the
 * administration's own query parameters are never touched.
 */

const STORAGE_KEY = 'fastmon-collector.oauth';

/** Where to land if the merchant started somewhere without a route of its own. */
const DEFAULT_RETURN = '#/sw/extension/config/FastmonCollector';

function read() {
    try {
        const raw = window.sessionStorage.getItem(STORAGE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        // Private mode, a storage quota, a browser that blocks it: no connection attempt
        // is recoverable then, and that is not worth breaking the administration over.
        return null;
    }
}

function write(value) {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

export function forget() {
    try {
        window.sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // Nothing to clean up if it could not be written in the first place.
    }
}

/**
 * Note that a connection was started here, and where to come back to.
 *
 * @returns {boolean} false when this browser cannot remember it, which is the one case
 *                    where the redirect would strand the merchant on the dashboard
 */
export function rememberAttempt() {
    return write({ returnTo: window.location.hash || DEFAULT_RETURN });
}

/**
 * Called on every administration boot. Does nothing at all unless this tab started a
 * connection and the current URL is the answer to it.
 */
export function captureCallback() {
    const pending = read();

    // No attempt in this tab, or one that was already taken out of the URL.
    if (pending === null || pending.state) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const state = params.get('state');
    const code = params.get('code');
    const error = params.get('error');

    // `state` is what makes this ours; a code or an error is what makes it an answer.
    if (!state || (!code && !error)) {
        return;
    }

    if (!write({ ...pending, state, code: code || '', error: error || '' })) {
        return;
    }

    // Drops the query - an authorization code has no business staying in the address bar,
    // in the history, or in a bookmark - and lands back on the page the merchant left.
    // A real navigation rather than a hash change, because the query is going away too.
    window.location.replace(window.location.pathname + (pending.returnTo || DEFAULT_RETURN));
}

/**
 * The answer fastmon sent, once. Reading it clears it, so a reload cannot try to redeem
 * a code that was already spent.
 *
 * @returns {{ code: string, state: string, error: string }|null}
 */
export function takeCallback() {
    const stored = read();

    if (stored === null || !stored.state) {
        return null;
    }

    forget();

    return {
        code: stored.code || '',
        state: stored.state,
        error: stored.error || '',
    };
}
