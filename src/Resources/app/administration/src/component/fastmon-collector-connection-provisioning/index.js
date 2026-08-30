import template from './fastmon-collector-connection-provisioning.html.twig';

const { Component } = Shopware;

/**
 * Choosing - or creating - the fastmon application the storefront reports to.
 *
 * Linking an existing application comes first: a second shop feeding one dashboard is
 * a normal setup, and creating a duplicate is the mistake that ordering prevents. The
 * organisation is only asked for when fastmon did not already say which one the
 * merchant approved for; offering a choice fastmon has already had made would invite
 * reporting to a different organisation than the one on the consent screen.
 *
 * Emits `linked` once an application is attached and `error` with whatever was refused.
 */
Component.register('fastmon-collector-connection-provisioning', {
    template,

    inject: ['fastmonCollectorService'],

    emits: ['linked', 'error'],

    props: {
        /** The connection status the parent loaded; read for the settled organisation. */
        status: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isBusy: false,
            isLoadingOrganizations: false,
            isLoadingApplications: false,
            organizations: [],
            applications: [],
            organizationId: '',
            applicationName: 'Shopware',
            environment: 'prod',
            preset: 'standard',
        };
    },

    computed: {
        needsRelink() {
            return this.status.provisioned === true && this.status.applicationValid === false;
        },

        /**
         * True once fastmon has told us which organisation the merchant approved for.
         * Then the picker is not just unnecessary, it is wrong.
         */
        organizationIsSettled() {
            return Boolean(this.status.organizationId);
        },

        settledOrganizationName() {
            return this.status.organizationName || this.status.organizationId;
        },

        organizationOptions() {
            return this.organizations.map((org) => ({ id: org.id, value: org.id, label: org.name }));
        },

        environmentOptions() {
            return [
                { id: 'prod', value: 'prod', label: this.$tc('fastmon-collector.environment.prod') },
                { id: 'dev', value: 'dev', label: this.$tc('fastmon-collector.environment.dev') },
            ];
        },

        presetOptions() {
            return [
                { id: 'minimal', value: 'minimal', label: this.$tc('fastmon-collector.preset.minimal') },
                { id: 'standard', value: 'standard', label: this.$tc('fastmon-collector.preset.standard') },
                { id: 'full', value: 'full', label: this.$tc('fastmon-collector.preset.full') },
            ];
        },

        /**
         * "Or create a new one" only reads as an alternative when there was something to
         * choose from. With an empty organisation it is the only option.
         */
        createTitle() {
            return this.applications.length
                ? this.$tc('fastmon-collector.provision.createTitle')
                : this.$tc('fastmon-collector.provision.createOnlyTitle');
        },
    },

    created() {
        if (this.organizationIsSettled) {
            this.organizationId = this.status.organizationId;
            this.loadApplications();

            return;
        }

        this.loadOrganizations();
    },

    methods: {
        loadOrganizations() {
            this.isLoadingOrganizations = true;

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
                .catch(this.fail)
                .finally(() => {
                    this.isLoadingOrganizations = false;
                });
        },

        loadApplications() {
            if (!this.organizationId) {
                this.applications = [];

                return Promise.resolve();
            }

            this.isLoadingApplications = true;

            return this.fastmonCollectorService.getApplications(this.organizationId)
                .then((response) => {
                    this.applications = response.applications || [];
                })
                .catch(this.fail)
                .finally(() => {
                    this.isLoadingApplications = false;
                });
        },

        onOrganizationChange(organizationId) {
            this.organizationId = organizationId || '';
            this.applications = [];
            this.loadApplications();
        },

        createApplication() {
            this.busy(() => this.fastmonCollectorService.createApplication({
                organizationId: this.organizationId,
                name: this.applicationName,
                environment: this.environment,
                preset: this.preset,
            }).then(() => this.$emit('linked')));
        },

        attachApplication(applicationId) {
            this.busy(() => this.fastmonCollectorService
                .attachApplication(this.organizationId, applicationId)
                .then(() => this.$emit('linked')));
        },

        busy(action) {
            this.isBusy = true;

            return action()
                .catch(this.fail)
                .finally(() => {
                    this.isBusy = false;
                });
        },

        fail(error) {
            this.$emit('error', error);

            return null;
        },
    },
});
