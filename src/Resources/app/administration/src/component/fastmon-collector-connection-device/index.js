import template from './fastmon-collector-connection-device.html.twig';
import { createDeviceAuthorizationPoller } from '../../util/device-authorization-poller';

const { Component } = Shopware;

/**
 * Getting a token into the shop.
 *
 * The shop cannot run the Authorization Code grant the fastmon *app* uses: a plugin runs
 * on the merchant's own server, so it can hold no client secret and owns no registered
 * redirect URI. It uses the Device Authorization Grant (RFC 8628) instead - the merchant
 * approves the connection in fastmon's own UI, and the shop polls until a token arrives.
 *
 * If this fastmon instance does not serve that grant yet, the backend answers
 * `unsupported` and this component swaps the button for a token field. Everything after
 * a token exists is identical either way, and lives in the parent.
 *
 * Emits `connected` once a token is stored and `error` with whatever fastmon or the
 * plugin refused; the parent renders both.
 */
Component.register('fastmon-collector-connection-device', {
    template,

    inject: ['fastmonCollectorService'],

    emits: ['connected', 'error'],

    data() {
        return {
            isBusy: false,

            // Device authorization in flight.
            device: null,

            // Set once fastmon answers `unsupported`, which is the only thing that
            // reveals the token field.
            deviceUnsupported: false,
            token: '',
        };
    },

    created() {
        this.poller = createDeviceAuthorizationPoller({
            poll: (handle) => this.fastmonCollectorService.pollDeviceAuthorization(handle),
            onComplete: () => {
                this.device = null;
                this.$emit('connected');
            },
            onExpired: () => {
                this.device = null;
                this.$emit('error', new Error(this.$tc('fastmon-collector.connect.expired')));
            },
            onError: (error) => {
                this.device = null;
                this.$emit('error', error);
            },
        });
    },

    beforeUnmount() {
        // A poll left running after the panel closes would keep hitting fastmon from a
        // component nobody can see the result in.
        this.poller.stop();
    },

    methods: {
        connect() {
            this.busy(() => this.fastmonCollectorService.startDeviceAuthorization()
                .then((response) => {
                    if (response.unsupported) {
                        // Nothing to poll for. The token field is the way in on this
                        // instance, and saying so beats a button that fails every time.
                        this.deviceUnsupported = true;

                        return;
                    }

                    this.device = response;
                    this.poller.start(response);
                }));
        },

        cancelDevice() {
            this.poller.stop();
            this.device = null;
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
