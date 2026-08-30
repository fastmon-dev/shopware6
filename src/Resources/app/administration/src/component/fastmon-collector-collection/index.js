import template from './fastmon-collector-collection.html.twig';
import './fastmon-collector-collection.scss';

const { Component } = Shopware;

const MODES = { DEFAULT: 'default', CUSTOM: 'custom', RELATIVE: 'relative' };

/**
 * Where the tracker is loaded from and where the beacon goes.
 *
 * Three choices, mirroring fastmon's collector modes. Two of them depend on the
 * merchant's own web server, and those cannot simply be selected: the collector endpoint
 * is baked into the bundle fastmon serves, so applying one before the server forwards
 * `/s/` and `/c/` makes every tracker in every browser post into a 404 — no error, no
 * data, and nobody notices for days. So the apply button stays out of reach until a probe
 * against the real origins has come back green for the mode being considered.
 *
 * The plugin never forwards those paths itself. A beacon per pageview through PHP-FPM
 * would put fastmon's latency in front of the shop's worker pool.
 */
Component.register('fastmon-collector-collection', {
    template,

    inject: ['fastmonCollectorService'],

    data() {
        return {
            isLoading: true,
            isBusy: false,
            isChecking: false,
            error: null,
            status: null,

            // What the merchant is considering, which is not what is stored until they
            // apply it — the probe has to run against the prospective mode.
            selectedMode: MODES.DEFAULT,
            customDomain: '',
            proxySecret: '',
        };
    },

    computed: {
        modes() {
            return MODES;
        },

        provisioned() {
            return this.status !== null && this.status.provisioned === true;
        },

        activeMode() {
            return this.status === null ? MODES.DEFAULT : this.status.mode;
        },

        storefrontOrigins() {
            return this.status === null ? [] : (this.status.storefrontOrigins || []);
        },

        checkedDomains() {
            return this.status === null ? [] : (this.status.domains || []);
        },

        /** The label of the mode being considered, for the sentence explaining the block. */
        selectedModeLabel() {
            const keys = {
                [MODES.DEFAULT]: 'modeDefault',
                [MODES.CUSTOM]: 'modeCustom',
                [MODES.RELATIVE]: 'modeRelative',
            };

            return this.$tc(`fastmon-collector.collection.${keys[this.selectedMode]}`);
        },

        /** A result only counts for the mode it was taken against. */
        checkedThisMode() {
            return this.status !== null
                && this.status.checked === true
                && this.status.checkedMode === this.selectedMode;
        },

        needsProof() {
            return this.selectedMode !== MODES.DEFAULT;
        },

        /**
         * fastmon's own collector needs nothing proven; the other two do.
         *
         * `needsProof` and `checkedThisMode` are computed properties, so they are read,
         * not called. Calling one throws, and because the early return above covers the
         * unchanged mode it only threw once the merchant picked a different one - which
         * blanked the whole card.
         */
        canApply() {
            if (this.selectedMode === this.activeMode) {
                return false;
            }

            return !this.needsProof || (this.checkedThisMode && this.status.ready === true);
        },

        canCheck() {
            if (!this.needsProof) {
                return false;
            }

            return this.selectedMode !== this.modes.CUSTOM || this.customDomain.trim() !== '';
        },
    },

    created() {
        this.load();
    },

    methods: {
        /**
         * The probe reports a reason code, not a sentence: everything else the merchant
         * reads here is translated, and "HTTP 404" on its own does not say what to do.
         */
        reasonText(domain) {
            if (!domain.reason) {
                return '';
            }

            return this.$tc(`fastmon-collector.collection.reason.${domain.reason}`, 0, {
                detail: domain.detail || '',
            });
        },

        load(probeMode = null) {
            this.isLoading = true;

            return this.fastmonCollectorService
                .getCollectionStatus(probeMode, this.customDomain)
                .then((status) => {
                    this.status = status;

                    if (probeMode === null) {
                        this.selectedMode = status.mode;
                        this.customDomain = status.customDomain || '';
                    }
                })
                .catch(this.handleError)
                .finally(() => {
                    this.isLoading = false;
                });
        },

        onModeChange() {
            // A result taken against another mode says nothing about this one.
            if (this.status !== null) {
                this.status = { ...this.status, checked: false, checkedMode: '', domains: [] };
            }
        },

        check() {
            this.isChecking = true;
            this.error = null;

            return this.load(this.selectedMode)
                .finally(() => {
                    this.isChecking = false;
                });
        },

        apply() {
            this.isBusy = true;
            this.error = null;

            return this.fastmonCollectorService
                .applyCollectionMode(this.selectedMode, this.customDomain)
                .then(() => {
                    this.proxySecret = '';

                    return this.load();
                })
                .catch(this.handleError)
                .finally(() => {
                    this.isBusy = false;
                });
        },

        generateProxySecret() {
            this.isBusy = true;
            this.error = null;

            return this.fastmonCollectorService.generateProxySecret()
                .then((response) => {
                    // Shown once and never stored here: it belongs in the proxy config.
                    this.proxySecret = response.proxySecret;
                })
                .catch(this.handleError)
                .finally(() => {
                    this.isBusy = false;
                });
        },

        handleError(error) {
            // A refusal carries the per-origin results instead of a message, so it is
            // rendered as a check result rather than as a red sentence in English.
            if (error && error.notReady) {
                this.error = null;
                this.status = {
                    ...this.status,
                    domains: error.domains || [],
                    checked: true,
                    checkedMode: this.selectedMode,
                    ready: false,
                };

                return null;
            }

            this.error = error && error.message ? error.message : String(error);

            return null;
        },
    },
});
