import template from './fastmon-collector-server-timing.html.twig';
import './fastmon-collector-server-timing.scss';

const { Component } = Shopware;

/**
 * Read-only panel: which measurement source answered on this host, and which layers it
 * reports.
 *
 * The layers shown are the ones measured for the admin API request that fetched them.
 * That is the point - it runs in the same PHP-FPM pool as the storefront, so it is a
 * real sample of this machine rather than a list of what could theoretically appear.
 */
Component.register('fastmon-collector-server-timing', {
    template,

    inject: ['fastmonCollectorService'],

    data() {
        return {
            isLoading: true,
            error: null,
            status: null,
        };
    },

    computed: {
        isAvailable() {
            return this.status !== null && this.status.available === true;
        },

        source() {
            return this.status === null ? '' : (this.status.source || '');
        },

        /**
         * Every source the plugin knows about, installed or not. A boolean "available"
         * cannot tell "Tideways is too old" from "Tideways is not installed", and those
         * are completely different afternoons - so each carries a state.
         */
        sources() {
            return this.status === null ? [] : (this.status.providers || []);
        },

        layers() {
            return this.status === null ? [] : (this.status.layers || []);
        },

        extensions() {
            return this.status === null ? [] : (this.status.extensions || []);
        },

        extensionSummary() {
            return this.extensions.length === 0
                ? this.$tc('fastmon-collector.serverTiming.noExtension')
                : this.extensions.join(', ');
        },

        /** Documented layers this host knows but the sampling request did not touch. */
        idleLayers() {
            if (this.status === null) {
                return [];
            }

            const reported = this.layers.map((layer) => layer.name);

            return (this.status.known || []).filter((name) => !reported.includes(name));
        },
    },

    created() {
        this.load();
    },

    methods: {
        load() {
            this.isLoading = true;
            this.error = null;

            return this.fastmonCollectorService.getServerTimingStatus()
                .then((status) => {
                    this.status = status;
                })
                .catch((error) => {
                    this.error = error && error.message ? error.message : String(error);
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        sourceState(source) {
            return this.$tc(`fastmon-collector.serverTiming.state.${source.state}`);
        },

        formatDuration(milliseconds) {
            return `${Number(milliseconds).toFixed(1)} ms`;
        },
    },
});
