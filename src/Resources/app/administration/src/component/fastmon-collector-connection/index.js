import template from './fastmon-collector-connection.html.twig';
import './fastmon-collector-connection.scss';

const { Component } = Shopware;

/**
 * The connect-and-provision panel in the plugin configuration.
 *
 * Rendered through <component> in config.xml, so it receives the usual plugin-config
 * props and ignores them: it writes through the plugin's own admin API rather than
 * through the config form, because connecting is a multi-step conversation with fastmon
 * and not a field the merchant types into.
 *
 * ## The connection flow
 *
 * The shop cannot run the Authorization Code grant the fastmon *app* uses: a plugin runs
 * on the merchant's own server, so it can hold no client secret and owns no registered
 * redirect URI. It uses the Device Authorization Grant (RFC 8628) instead - the merchant
 * approves the connection in fastmon's own UI, and the shop polls until a token arrives.
 *
 * If this fastmon instance does not serve that grant yet, the backend answers
 * `unsupported` and this component swaps the button for a token field. Everything after
 * a token exists is identical either way.
 */
Component.register('fastmon-collector-connection', {
    template,

    inject: ['fastmonCollectorService'],

    data() {
        return {
            isLoading: true,
            isBusy: false,
            error: null,
            status: null,

            // Device authorization in flight.
            device: null,
            pollTimer: null,
            pollInterval: 5000,
            expiresAt: null,

            // Set once fastmon answers `unsupported`, which is the only thing that
            // reveals the token field.
            deviceUnsupported: false,
            token: '',

            // fastmon gates ingestion behind an org review, so a brand-new account can
            // connect and still not be able to create an application yet.
            pendingApproval: false,

            // Set when fastmon rejected a call for a permission the token lacks. It
            // names the missing one, so the merchant can add it instead of guessing.
            missingPermission: '',


            // Provisioning.
            organizations: [],
            applications: [],
            sites: [],
            organizationId: '',
            applicationName: 'Shopware',
            environment: 'prod',
            preset: 'standard',
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

        environmentOptions() {
            return [
                { value: 'prod', label: this.$tc('fastmon-collector.environment.prod') },
                { value: 'dev', label: this.$tc('fastmon-collector.environment.dev') },
            ];
        },

        presetOptions() {
            return [
                { value: 'minimal', label: this.$tc('fastmon-collector.preset.minimal') },
                { value: 'standard', label: this.$tc('fastmon-collector.preset.standard') },
                { value: 'full', label: this.$tc('fastmon-collector.preset.full') },
            ];
        },

        organizationOptions() {
            return this.organizations.map((org) => ({ value: org.id, label: org.name }));
        },

        /**
         * True once fastmon has told us which organisation the merchant approved for.
         * Then the picker below is not just unnecessary, it is wrong: offering a choice
         * fastmon has already had made invites reporting to a different organisation
         * than the one on the consent screen.
         */
        organizationIsSettled() {
            return this.status !== null && Boolean(this.status.organizationId);
        },

        settledOrganizationName() {
            if (this.status === null) {
                return '';
            }

            return this.status.organizationName || this.status.organizationId;
        },
    },

    created() {
        this.load(true);
    },

    beforeUnmount() {
        // A poll left running after the panel closes would keep hitting fastmon from a
        // component nobody can see the result in.
        this.stopPolling();
    },

    methods: {
        load(verify = false) {
            this.isLoading = true;

            return this.fastmonCollectorService.getStatus(verify)
                .then((status) => {
                    this.status = status;
                    this.error = status.error || null;

                    const mustChoose = status.connected
                        && status.tokenValid !== false
                        && (!status.provisioned || status.applicationValid === false);

                    if (mustChoose) {
                        // Only ask which organisation when fastmon did not already say.
                        if (status.organizationId) {
                            this.organizationId = status.organizationId;

                            return this.loadApplications();
                        }

                        return this.loadOrganizations();
                    }

                    // Linked and healthy: show which domains fastmon has actually seen.
                    if (status.provisioned && status.applicationValid !== false) {
                        return this.loadSites();
                    }

                    return null;
                })
                .catch(this.handleError)
                .finally(() => {
                    this.isLoading = false;
                });
        },

        // ---- connecting ------------------------------------------------------

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
                    this.pollInterval = Math.max(1, response.interval || 5) * 1000;
                    this.expiresAt = Date.now() + ((response.expiresIn || 600) * 1000);
                    this.startPolling();
                }));
        },

        startPolling() {
            this.stopPolling();
            this.pollTimer = window.setTimeout(this.poll, this.pollInterval);
        },

        stopPolling() {
            if (this.pollTimer !== null) {
                window.clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
        },

        poll() {
            if (this.device === null) {
                return;
            }

            if (this.expiresAt !== null && Date.now() > this.expiresAt) {
                this.cancelDevice();
                this.error = this.$tc('fastmon-collector.connect.expired');

                return;
            }

            this.fastmonCollectorService.pollDeviceAuthorization(this.device.handle)
                .then((response) => {
                    if (response.status === 'complete') {
                        this.device = null;
                        this.stopPolling();

                        return this.load(false);
                    }

                    if (response.status === 'expired') {
                        this.cancelDevice();
                        this.error = this.$tc('fastmon-collector.connect.expired');

                        return null;
                    }

                    // RFC 8628: `slow_down` means add five seconds and carry on. Not
                    // honouring it gets the shop rate limited out of its own connection.
                    if (response.status === 'slow_down') {
                        this.pollInterval += 5000;
                    }

                    this.startPolling();

                    return null;
                })
                .catch((error) => {
                    // Declined or expired on fastmon's side: terminal, so stop rather
                    // than hammer an authorization that will never complete.
                    this.cancelDevice();
                    this.handleError(error);
                });
        },

        cancelDevice() {
            this.stopPolling();
            this.device = null;
            this.expiresAt = null;
        },

        connectWithToken() {
            this.busy(() => this.fastmonCollectorService.connectWithToken(this.token)
                .then(() => {
                    // Never keep the pasted secret in component state once it is stored.
                    this.token = '';

                    return this.load(false);
                }));
        },

        disconnect() {
            this.busy(() => this.fastmonCollectorService.disconnect()
                .then(() => {
                    this.cancelDevice();
                    this.deviceUnsupported = false;
                    this.organizations = [];
                    this.applications = [];

                    return this.load(false);
                }));
        },

        // ---- provisioning ----------------------------------------------------

        loadOrganizations() {
            return this.fastmonCollectorService.getOrganizations()
                .then((response) => {
                    this.organizations = response.organizations || [];

                    // One organization is the common case; preselecting it removes a
                    // click that has no alternative to choose from.
                    if (this.organizations.length === 1) {
                        this.organizationId = this.organizations[0].id;

                        return this.loadApplications();
                    }

                    return null;
                })
                .catch(this.handleError);
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

        loadApplications() {
            if (!this.organizationId) {
                this.applications = [];

                return Promise.resolve();
            }

            return this.fastmonCollectorService.getApplications(this.organizationId)
                .then((response) => {
                    this.applications = response.applications || [];
                })
                .catch(this.handleError);
        },

        onOrganizationChange() {
            this.applications = [];
            this.loadApplications();
        },

        createApplication() {
            this.busy(() => this.fastmonCollectorService.createApplication({
                organizationId: this.organizationId,
                name: this.applicationName,
                environment: this.environment,
                preset: this.preset,
            }).then(() => this.load(false)));
        },

        attachApplication(applicationId) {
            this.busy(() => this.fastmonCollectorService
                .attachApplication(this.organizationId, applicationId)
                .then(() => this.load(false)));
        },

        refreshApplication() {
            // Catches a hash rotated in the dashboard, which otherwise leaves the shop
            // serving a dead embed while looking perfectly healthy.
            this.busy(() => this.fastmonCollectorService.refreshApplication()
                .then(() => this.load(true)));
        },

        // ---- plumbing --------------------------------------------------------

        busy(action) {
            this.isBusy = true;
            this.error = null;
            this.pendingApproval = false;
            this.missingPermission = '';

            return action()
                .catch(this.handleError)
                .finally(() => {
                    this.isBusy = false;
                });
        },

        handleError(error) {
            this.error = error && error.message ? error.message : String(error);
            this.pendingApproval = Boolean(error && error.pendingApproval);
            this.missingPermission = (error && error.permission) || '';

            if (error && error.reconnect && this.status !== null) {
                this.status = { ...this.status, tokenValid: false };
            }

            return null;
        },
    },
});
