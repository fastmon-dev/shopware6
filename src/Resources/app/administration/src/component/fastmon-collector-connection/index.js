import template from './fastmon-collector-connection.html.twig';
import './fastmon-collector-connection.scss';
import { connectionChanged } from '../../util/panel-bus';
import { forget as forgetCallback } from '../../util/oauth-callback';

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
    },

    methods: {
        load(verify = false) {
            this.isLoading = true;

            return this.fastmonCollectorService.getStatus(verify)
                .then((status) => {
                    // Which application the storefront reports for is what the other
                    // panels depend on, so a change to it is announced rather than
                    // waiting for the merchant to reload the page.
                    const linkChanged = this.status !== null && this.status.sourceHash !== status.sourceHash;

                    this.status = status;
                    this.error = status.error || null;

                    // A callback that arrived for a shop that is already connected (a
                    // second tab finished the flow, or a key was pasted meanwhile) has
                    // nobody to redeem it: the authorize child only mounts while there
                    // is no connection. Dropped here, or it would sit in sessionStorage
                    // for the life of the tab and be redeemed on the next disconnect.
                    if (status.connected) {
                        forgetCallback();
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

        /**
         * The panel switches out of the provisioning state from the response that
         * linked, not from the reload that follows it. The reload is still worth
         * making - it brings the domains and whatever else moved - but the state the
         * merchant is waiting for is already in hand, and making the switch wait for a
         * second round trip is what left the form standing after a successful create.
         */
        onLinked(application) {
            this.resetError();

            if (application && application.sourceHash) {
                this.status = {
                    ...this.status,
                    provisioned: true,
                    applicationId: application.id,
                    sourceHash: application.sourceHash,
                    collectorHash: application.collectorHash,
                };

                connectionChanged();
            }

            return this.load(false);
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
