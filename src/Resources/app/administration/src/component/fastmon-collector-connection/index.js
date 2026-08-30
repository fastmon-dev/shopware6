import template from './fastmon-collector-connection.html.twig';
import './fastmon-collector-connection.scss';

const { Component, Mixin } = Shopware;

/**
 * The connect-and-provision panel in the plugin configuration.
 *
 * Three states, three components. This one holds the status and decides which of the
 * other two is on screen: `fastmon-collector-connection-device` until a token exists,
 * `fastmon-collector-connection-provisioning` until an application is linked, and the
 * summary once both are. The children report back with events; every error, wherever it
 * came from, is rendered here, once.
 *
 * Rendered through <component> in config.xml, so it receives the usual plugin-config
 * props and ignores them: it writes through the plugin's own admin API rather than
 * through the config form, because connecting is a multi-step conversation with fastmon
 * and not a field the merchant types into.
 */
Component.register('fastmon-collector-connection', {
    template,

    inject: ['fastmonCollectorService'],

    mixins: [Mixin.getByName('fastmon-collector-error')],

    // The form-field props from sw-system-config land in $attrs and stay there.
    inheritAttrs: false,

    data() {
        return {
            isLoading: true,
            isBusy: false,
            status: null,
            sites: [],
        };
    },

    computed: {
        isConnected() {
            return this.status !== null && this.status.connected === true;
        },

        isProvisioned() {
            return this.status !== null && this.status.provisioned === true;
        },

        /** A stored token fastmon rejected: connected, but nothing will work. */
        needsReconnect() {
            return this.isConnected && this.status.tokenValid === false;
        },

        /**
         * The token is fine but the application it points at is not: deleted in the
         * dashboard, or its hashes rotated. Worth its own state, because disconnecting
         * would throw away a perfectly good credential to fix something else.
         */
        needsRelink() {
            return this.isProvisioned && this.status.applicationValid === false;
        },

        isLinked() {
            return this.isProvisioned && !this.needsRelink && !this.needsReconnect;
        },

        connectedAs() {
            return this.status.accountEmail || this.status.accountName;
        },

        organizationLabel() {
            return this.status.organizationName || this.status.organizationId;
        },
    },

    created() {
        this.load(true);
    },

    methods: {
        load(verify = false) {
            this.isLoading = true;

            return this.fastmonCollectorService.getStatus(verify)
                .then((status) => {
                    this.status = status;
                    this.error = status.error || null;

                    // Linked and healthy: show which domains fastmon has actually seen.
                    if (status.provisioned && status.applicationValid !== false) {
                        return this.loadSites();
                    }

                    this.sites = [];

                    return null;
                })
                .catch(this.applyFastmonError)
                .finally(() => {
                    this.isLoading = false;
                });
        },

        loadSites() {
            // The domains fastmon has seen. Failing to load them must not take the rest
            // of the panel with it - they are information, not state.
            return this.fastmonCollectorService.getSites()
                .then((response) => {
                    this.sites = response.sites || [];
                })
                .catch(() => {
                    this.sites = [];
                });
        },

        onConnected() {
            this.resetError();

            return this.load(false);
        },

        onLinked() {
            this.resetError();

            return this.load(false);
        },

        refreshApplication() {
            // Catches a hash rotated in the dashboard, which otherwise leaves the shop
            // serving a dead embed while looking perfectly healthy.
            this.busy(() => this.fastmonCollectorService.refreshApplication()
                .then(() => this.load(true)));
        },

        disconnect() {
            this.busy(() => this.fastmonCollectorService.disconnect()
                .then(() => this.load(false)));
        },

        busy(action) {
            this.isBusy = true;
            this.resetError();

            return action()
                .catch(this.applyFastmonError)
                .finally(() => {
                    this.isBusy = false;
                });
        },
    },
});
