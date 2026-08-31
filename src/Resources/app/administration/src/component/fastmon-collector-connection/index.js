import template from './fastmon-collector-connection.html.twig';
import './fastmon-collector-connection.scss';
import { connectionChanged, onStorefrontChanged } from '../../util/panel-bus';

const { Component, Mixin } = Shopware;

/**
 * The connect-and-provision panel in the plugin configuration.
 *
 * Three states, three components. This one holds the status and decides which of the
 * other two is on screen: `fastmon-collector-connection-authorize` until a credential
 * exists,
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

            // Set by whatever just changed something the storefront renders: this panel
            // linking an application, the card below applying a mode, or the status call
            // reporting that it adopted new hashes from fastmon. Deliberately not stored
            // anywhere: it says what happened, and a reload is a fresh start.
            cacheStale: false,
        };
    },

    computed: {
        isConnected() {
            return this.status !== null && this.status.connected === true;
        },

        isProvisioned() {
            return this.status !== null && this.status.provisioned === true;
        },

        /** A stored credential fastmon rejected: connected, but nothing will work. */
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

        /**
         * Who approved the connection, shown beside the organisation rather than in
         * place of it. An app connection outlives this person, and a pasted key names
         * nobody at all: it belongs to the dashboard, not to a session.
         */
        approvedBy() {
            return this.status.accountEmail || this.status.accountName;
        },

        /**
         * Permissions the plugin asked for and did not get. Worth saying out loud,
         * because everything looks connected until the first call that needs one fails
         * with a message from the API rather than from this panel.
         */
        missingScopes() {
            return this.status !== null && Array.isArray(this.status.missingScopes)
                ? this.status.missingScopes
                : [];
        },

        organizationLabel() {
            return this.status.organizationName || this.status.organizationId;
        },
    },

    created() {
        this.load(true);

        // The collection card below changes what the storefront renders as well, and the
        // notice for it lives up here.
        this.stopListening = onStorefrontChanged(() => {
            this.cacheStale = true;

            return this.load(false);
        });
    },

    beforeUnmount() {
        this.stopListening();
    },

    methods: {
        load(verify = false) {
            this.isLoading = true;

            return this.fastmonCollectorService.getStatus(verify)
                .then((status) => {
                    // Which application the storefront reports for is what the other
                    // panels depend on, so a change to it is announced rather than
                    // waiting for the merchant to reload the page.
                    const linkChanged = this.status !== null && this.status.trackerId !== status.trackerId;

                    this.status = status;
                    this.error = status.error || null;

                    if (status.cacheStale === true) {
                        this.cacheStale = true;
                    }

                    if (linkChanged) {
                        connectionChanged();
                    }

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
            // The storefront renders the tracker id, and the pages in the cache do not
            // carry it yet.
            this.cacheStale = true;

            return this.load(false);
        },

        clearCache() {
            this.busy(() => this.fastmonCollectorService.clearStorefrontCache()
                .then(() => {
                    this.cacheStale = false;

                    return this.load(false);
                }));
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
