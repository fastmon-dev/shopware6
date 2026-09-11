import template from './fastmon-collector-collection.html.twig';
import './fastmon-collector-collection.scss';
import { onConnectionChanged } from '../../util/panel-bus';

const { Component, Mixin } = Shopware;

const MODES = { DEFAULT: 'default', CUSTOM: 'custom', RELATIVE: 'relative' };

/**
 * Where the tracker is loaded from and where the beacon goes.
 *
 * Three choices, mirroring fastmon's collector modes. Two of them depend on the
 * merchant's own web server, and those cannot simply be selected: the collector endpoint
 * is baked into the bundle fastmon serves, so applying one before the server forwards
 * `/s/` and `/c/` makes every tracker in every browser post into a 404: no error, no
 * data, and nobody notices for days. So the apply button stays out of reach until a probe
 * against the real origins has come back green for the mode being considered.
 *
 * The plugin never forwards those paths itself. A beacon per pageview through PHP-FPM
 * would put fastmon's latency in front of the shop's worker pool.
 */
Component.register('fastmon-collector-collection', {
    template,

    inject: ['fastmonCollectorService'],

    mixins: [Mixin.getByName('fastmon-collector-error')],

    inheritAttrs: false,

    data() {
        return {
            isLoading: true,
            isBusy: false,
            isChecking: false,
            status: null,

            // What the merchant is considering, which is not what is stored until they
            // apply it, because the probe has to run against the prospective mode.
            selectedMode: MODES.DEFAULT,
            customDomain: '',
            proxySecret: '',
        };
    },

    computed: {
        modes() {
            return MODES;
        },

        modeOptions() {
            return [
                {
                    value: MODES.DEFAULT,
                    name: this.$t('fastmon-collector.collection.modeDefault'),
                    description: this.$t('fastmon-collector.collection.modeDefaultHint'),
                },
                {
                    value: MODES.CUSTOM,
                    name: this.$t('fastmon-collector.collection.modeCustom'),
                    description: this.$t('fastmon-collector.collection.modeCustomHint'),
                },
                {
                    value: MODES.RELATIVE,
                    name: this.$t('fastmon-collector.collection.modeRelative'),
                    description: this.$t('fastmon-collector.collection.modeRelativeHint'),
                },
            ];
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
            const option = this.modeOptions.find((candidate) => candidate.value === this.selectedMode);

            return option ? option.name : this.selectedMode;
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

        /** fastmon's own collector needs nothing proven; the other two do. */
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

            return this.selectedMode !== MODES.CUSTOM || this.customDomain.trim() !== '';
        },
    },

    created() {
        this.load();

        // Linking an application happens in the panel above this one. Without this, the
        // collection card keeps saying that nothing is linked until the page is reloaded.
        this.stopListening = onConnectionChanged(() => this.load());
    },

    beforeUnmount() {
        this.stopListening();
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

            return this.$t(`fastmon-collector.collection.reason.${domain.reason}`, {
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

        onModeChange(mode) {
            this.selectedMode = mode;
            this.invalidateCheck();
        },

        onCustomDomainInput(domain) {
            this.customDomain = domain;
            this.invalidateCheck();
        },

        invalidateCheck() {
            // A result taken against another mode - or another domain - says nothing
            // about this one.
            if (this.status !== null) {
                this.status = { ...this.status, checked: false, checkedMode: '', domains: [] };
            }
        },

        check() {
            this.isChecking = true;
            this.resetError();

            return this.load(this.selectedMode)
                .finally(() => {
                    this.isChecking = false;
                });
        },

        apply() {
            this.isBusy = true;
            this.resetError();

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
            this.resetError();

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
                this.resetError();
                this.status = {
                    ...this.status,
                    domains: error.domains || [],
                    checked: true,
                    checkedMode: this.selectedMode,
                    ready: false,
                };

                return null;
            }

            return this.applyFastmonError(error);
        },
    },
});
