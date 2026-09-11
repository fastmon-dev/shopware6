import template from './fastmon-collector-connection-authorize.html.twig';
import { rememberAttempt, takeCallback, forget } from '../../util/oauth-callback';

const { Component } = Shopware;

/**
 * Getting this shop a fastmon credential.
 *
 * The good path is an **app connection**: the shop registers itself with fastmon as a
 * public OAuth client, the merchant approves it on fastmon's own consent screen, and the
 * shop ends up holding tokens that expire and rotate. Nothing secret ships in the plugin
 * and nothing is typed by hand - which is what a plugin distributed through the Store can
 * honestly offer.
 *
 * It takes the merchant off this page, and that is what the two halves here are for:
 * `connect` notes where they were and sends them to fastmon, `created` picks up the answer
 * that `util/oauth-callback` carried back through the reload.
 *
 * The field for an API key stays, one click away, because the redirect cannot work
 * everywhere: an administration served over plain http has no address fastmon is allowed
 * to send a code to. When the shop says so, that field opens without being asked.
 *
 * Emits `connected` once a credential is stored and `error` with whatever fastmon or the
 * plugin refused; the parent renders both.
 */
Component.register('fastmon-collector-connection-authorize', {
    template,

    inject: ['fastmonCollectorService'],

    emits: ['connected', 'error'],

    data() {
        return {
            isBusy: false,

            // Set when this shop cannot run the guided connection at all, which is the
            // only thing that opens the key field on its own.
            unsupported: false,
            unsupportedReason: '',

            showToken: false,
            token: '',
        };
    },

    created() {
        this.completeAuthorization();
    },

    methods: {
        connect() {
            if (!rememberAttempt()) {
                this.$emit('error', new Error(this.$t('fastmon-collector.connect.noSessionStorage')));

                return;
            }

            // The administration's own address: the only thing that knows where it is
            // really served from. The shop checks it against its own APP_URL before it
            // registers anything with it.
            const location = `${window.location.origin}${window.location.pathname}`;

            this.busy(() => this.fastmonCollectorService.startAuthorization(location)
                .then((response) => {
                    // Leaving the page, so the loading state stays on until it goes.
                    window.location.assign(response.authorizeUrl);
                })
                .catch((error) => {
                    forget();

                    if (error.unsupported) {
                        // Not something the merchant can retry: this shop has no address
                        // fastmon may send a code to, so the key field is the way in.
                        this.unsupported = true;
                        this.unsupportedReason = error.message;
                        this.showToken = true;

                        return;
                    }

                    throw error;
                }));
        },

        /** The other side of the redirect: true only on the load right after a consent screen. */
        completeAuthorization() {
            const callback = takeCallback();

            if (callback === null) {
                return;
            }

            this.busy(() => this.fastmonCollectorService.completeAuthorization(callback)
                .then(() => this.$emit('connected')));
        },

        connectWithToken() {
            this.busy(() => this.fastmonCollectorService.connectWithToken(this.token)
                .then(() => {
                    // Never keep the pasted secret in component state once it is stored.
                    this.token = '';
                    this.$emit('connected');
                }));
        },

        busy(action) {
            this.isBusy = true;

            return action()
                .catch((error) => this.$emit('error', error))
                .finally(() => {
                    this.isBusy = false;
                });
        },
    },
});
