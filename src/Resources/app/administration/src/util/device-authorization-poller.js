/**
 * Polls a device authorization (RFC 8628 §3.4) until it completes, expires or fails.
 *
 * Plain JavaScript with no Vue and no Shopware globals, because the timing rules are
 * what this file is for and they are easier to read - and to test - without a component
 * around them: the interval fastmon asked for, five more seconds on every `slow_down`,
 * a hard stop at `expires_in`, and never a second poll while one is in flight.
 *
 * @param {object} deps
 * @param {(handle: string) => Promise<{ status: string }>} deps.poll one poll against the plugin's admin API
 * @param {() => void} deps.onComplete the token arrived and is stored
 * @param {() => void} deps.onExpired the code ran out before it was approved
 * @param {(error: unknown) => void} deps.onError declined, or the call failed - terminal either way
 * @param {{ setTimeout: Function, clearTimeout: Function }} [deps.timers]
 * @param {() => number} [deps.now]
 */
export function createDeviceAuthorizationPoller({ poll, onComplete, onExpired, onError, timers = window, now = Date.now }) {
    const SLOW_DOWN_STEP = 5000;

    let timer = null;
    let handle = '';
    let interval = 5000;
    let expiresAt = 0;

    function stop() {
        if (timer !== null) {
            timers.clearTimeout(timer);
            timer = null;
        }

        handle = '';
    }

    function schedule() {
        timer = timers.setTimeout(tick, interval);
    }

    function tick() {
        timer = null;

        if (handle === '') {
            return;
        }

        if (now() > expiresAt) {
            stop();
            onExpired();

            return;
        }

        const current = handle;

        poll(current)
            .then((response) => {
                // Cancelled while the request was in flight: its answer is nobody's.
                if (handle !== current) {
                    return;
                }

                if (response.status === 'complete') {
                    stop();
                    onComplete();

                    return;
                }

                if (response.status === 'expired') {
                    stop();
                    onExpired();

                    return;
                }

                // RFC 8628 §3.5: `slow_down` means add five seconds and carry on. Not
                // honouring it gets the shop rate limited out of its own connection.
                if (response.status === 'slow_down') {
                    interval += SLOW_DOWN_STEP;
                }

                schedule();
            })
            .catch((error) => {
                // Declined or expired on fastmon's side: terminal, so stop rather than
                // hammer an authorization that will never complete.
                stop();
                onError(error);
            });
    }

    return {
        /** @param {{ handle: string, interval?: number, expiresIn?: number }} device */
        start(device) {
            stop();
            handle = device.handle;
            interval = Math.max(1, device.interval || 5) * 1000;
            expiresAt = now() + ((device.expiresIn || 600) * 1000);
            schedule();
        },

        stop,

        isRunning() {
            return handle !== '';
        },
    };
}
