const { Application, Classes: { ApiService } } = Shopware;

/**
 * The admin module's client for the plugin's own admin API.
 *
 * Every call answers `{ success, ... }` with HTTP 200 even when it failed, because the
 * failures here are things the merchant is meant to read next to the button they
 * pressed - a declined authorization, a revoked token, an unreachable fastmon. Letting
 * the administration's global handler turn those into a context-free toast would be
 * worse. `unwrap()` is what turns that convention back into a rejected promise.
 */
class FastmonCollectorService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/fastmon-collector') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'fastmonCollectorService';
    }

    getStatus(verify = false) {
        return this.read('/status', { verify: verify ? 1 : 0 });
    }

    startDeviceAuthorization() {
        return this.write('/connect/device');
    }

    pollDeviceAuthorization(handle) {
        return this.write('/connect/device/poll', { handle });
    }

    connectWithToken(token) {
        return this.write('/connect/token', { token });
    }

    disconnect() {
        return this.write('/disconnect');
    }

    getOrganizations() {
        return this.read('/organizations');
    }

    getApplications(organizationId) {
        return this.read('/applications', { organizationId });
    }

    createApplication(payload) {
        return this.write('/applications', payload);
    }

    attachApplication(organizationId, applicationId) {
        return this.write('/applications/attach', { organizationId, applicationId });
    }

    getSites() {
        return this.read('/sites');
    }

    refreshApplication() {
        return this.write('/applications/refresh');
    }

    getCollectionStatus(probeMode = null, domain = '') {
        // `check` names the mode being considered, not a boolean: a probe result only
        // counts for the mode it was taken against.
        return this.read('/collection', { check: probeMode || '', domain });
    }

    applyCollectionMode(mode, domain = '') {
        return this.write('/collection', { mode, domain });
    }

    generateProxySecret() {
        return this.write('/collection/secret');
    }

    getServerTimingStatus() {
        return this.read('/server-timing');
    }

    read(path, params = {}) {
        return this.httpClient
            .get(`${this.getApiBasePath()}${path}`, { params, headers: this.getBasicHeaders() })
            .then((response) => this.unwrap(ApiService.handleResponse(response)));
    }

    write(path, payload = {}) {
        return this.httpClient
            .post(`${this.getApiBasePath()}${path}`, payload, { headers: this.getBasicHeaders() })
            .then((response) => this.unwrap(ApiService.handleResponse(response)));
    }

    /**
     * Turn `{ success: false, error }` into a rejection carrying the message, and keep
     * `reconnect` on it so the caller can drop straight to the connect screen instead of
     * only showing text.
     */
    unwrap(data) {
        if (data && data.success === false) {
            const error = new Error(data.error || 'Unknown fastmon error');
            error.reconnect = data.reconnect === true;
            error.pendingApproval = data.pendingApproval === true;
            error.permission = typeof data.permission === 'string' ? data.permission : '';
            error.notReady = data.notReady === true;
            error.domains = data.domains || [];

            return Promise.reject(error);
        }

        return data;
    }
}

Application.addServiceProvider('fastmonCollectorService', (container) => new FastmonCollectorService(
    Application.getContainer('init').httpClient,
    container.loginService,
));

export default FastmonCollectorService;
