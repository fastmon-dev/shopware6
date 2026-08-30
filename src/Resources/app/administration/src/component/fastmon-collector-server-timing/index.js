import template from './fastmon-collector-server-timing.html.twig';
import './fastmon-collector-server-timing.scss';

const { Component } = Shopware;

// A provider reports an internal id. Product names are not translated, so they belong
// here rather than in the snippet files.
const DISPLAY_NAMES = {
    tideways: 'Tideways',
};

/**
 * Read-only panel: which measurement sources exist on this host and what each is doing.
 */
Component.register('fastmon-collector-server-timing', {
    template,

    inject: ['fastmonCollectorService'],

    inheritAttrs: false,

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

        /**
         * Installed or not. A boolean cannot tell "Tideways is too old" from "Tideways is
         * not installed", and those call for different fixes.
         */
        sources() {
            return this.status === null ? [] : (this.status.providers || []);
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

        sourceName(source) {
            return DISPLAY_NAMES[source.name] || source.name;
        },

        sourceState(source) {
            return this.$tc(`fastmon-collector.serverTiming.state.${source.state}`);
        },

    },
});
