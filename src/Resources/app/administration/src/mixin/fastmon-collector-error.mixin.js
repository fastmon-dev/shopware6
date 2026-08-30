const { Mixin } = Shopware;

/**
 * The one place a rejected fastmon call is turned into panel state.
 *
 * `fastmonCollectorService.unwrap()` rejects with an Error that carries the flags the
 * admin API sends next to its message: `reconnect`, `pendingApproval`, `permission`,
 * `notReady`, `domains`. Every panel used to read those on its own, so a new flag was a
 * change in three files. Now the service produces them and this mixin consumes them;
 * a panel that needs one more only adds the rendering.
 */
Mixin.register('fastmon-collector-error', {
    data() {
        return {
            error: null,

            // fastmon gates ingestion behind an org review, so a brand-new account can
            // connect and still not be able to create an application yet.
            pendingApproval: false,

            // Set when fastmon rejected a call for a permission the token lacks. It
            // names the missing one, so the merchant can add it instead of guessing.
            missingPermission: '',
        };
    },

    computed: {
        /** Waiting is not failing: a pending review is amber, because there is nothing to fix. */
        errorVariant() {
            return this.pendingApproval ? 'attention' : 'critical';
        },
    },

    methods: {
        resetError() {
            this.error = null;
            this.pendingApproval = false;
            this.missingPermission = '';
        },

        applyFastmonError(error) {
            this.error = error && error.message ? error.message : String(error);
            this.pendingApproval = Boolean(error && error.pendingApproval);
            this.missingPermission = (error && error.permission) || '';

            // A rejected token: whoever holds the status drops to the reconnect view.
            if (error && error.reconnect && this.status) {
                this.status = { ...this.status, tokenValid: false };
            }

            return null;
        },
    },
});
