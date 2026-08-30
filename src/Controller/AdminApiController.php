<?php declare(strict_types=1);

namespace Fastmon\Collector\Controller;

use Fastmon\Collector\Api\DeviceFlowUnsupportedException;
use Fastmon\Collector\Api\FastmonApiException;
use Fastmon\Collector\Api\FastmonOrganizationNotApprovedException;
use Fastmon\Collector\Api\FastmonPermissionDeniedException;
use Fastmon\Collector\Api\FastmonUnauthorizedException;
use Fastmon\Collector\Collection\CollectionMode;
use Fastmon\Collector\Collection\CollectionModeService;
use Fastmon\Collector\Collection\CollectionNotReadyException;
use Fastmon\Collector\Connection\ConnectionService;
use Fastmon\Collector\Provisioning\ApplicationProvisioner;
use Fastmon\Collector\ServerTiming\ServerTimingStatus;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin module's back end: connecting to fastmon, choosing an application, and
 * reporting what Server-Timing can see on this host.
 *
 * Every route is admin-API scoped and gated on the `system_config` ACL, which is the
 * right tier: everything here reads or writes the same rows the plugin configuration
 * screen does, and the token in particular is exactly as sensitive as the payment
 * credentials sitting next to it.
 *
 * fastmon errors are translated into a `{success: false, error}` body rather than an
 * exception, because all of them are things the merchant is meant to read and act on -
 * a declined authorization, a revoked token, an unreachable API - and none of them are
 * faults in the shop.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
final class AdminApiController extends AbstractController
{
    private const READ = [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']];
    private const WRITE = [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']];

    public function __construct(
        private readonly ConnectionService $connection,
        private readonly ApplicationProvisioner $provisioner,
        private readonly CollectionModeService $collection,
        private readonly ServerTimingStatus $serverTimingStatus,
    ) {
    }

    #[Route(
        path: '/api/_action/fastmon-collector/status',
        name: 'api.action.fastmon_collector.status',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function status(Request $request): JsonResponse
    {
        // Verification is a round trip to fastmon, so the panel asks for it when it
        // opens and skips it while polling.
        $verify = $request->query->getBoolean('verify');

        return $this->ok($this->connection->describe($verify));
    }

    #[Route(
        path: '/api/_action/fastmon-collector/connect/device',
        name: 'api.action.fastmon_collector.connect.device',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function startDeviceAuthorization(): JsonResponse
    {
        return $this->guard(function (): array {
            try {
                return $this->connection->startDeviceAuthorization();
            } catch (DeviceFlowUnsupportedException) {
                // Not a fault, and the answer is specific enough to be worth its own
                // flag: the module swaps the button for the token field.
                return ['unsupported' => true];
            }
        });
    }

    #[Route(
        path: '/api/_action/fastmon-collector/connect/device/poll',
        name: 'api.action.fastmon_collector.connect.device.poll',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function pollDeviceAuthorization(Request $request): JsonResponse
    {
        $handle = (string) $this->body($request)['handle'];

        return $this->guard(fn (): array => $this->connection->pollDeviceAuthorization($handle));
    }

    #[Route(
        path: '/api/_action/fastmon-collector/connect/token',
        name: 'api.action.fastmon_collector.connect.token',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function connectWithToken(Request $request): JsonResponse
    {
        $token = (string) $this->body($request)['token'];

        return $this->guard(fn (): array => $this->connection->connectWithToken($token));
    }

    #[Route(
        path: '/api/_action/fastmon-collector/disconnect',
        name: 'api.action.fastmon_collector.disconnect',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function disconnect(): JsonResponse
    {
        $this->connection->disconnect();

        return $this->ok([]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/organizations',
        name: 'api.action.fastmon_collector.organizations',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function organizations(): JsonResponse
    {
        return $this->guard(fn (): array => ['organizations' => $this->provisioner->organizations()]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/applications',
        name: 'api.action.fastmon_collector.applications',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function applications(Request $request): JsonResponse
    {
        $organizationId = (string) $request->query->get('organizationId', '');

        return $this->guard(
            fn (): array => ['applications' => $this->provisioner->applications($organizationId)]
        );
    }

    #[Route(
        path: '/api/_action/fastmon-collector/applications',
        name: 'api.action.fastmon_collector.applications.create',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function createApplication(Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $this->guard(fn (): array => ['application' => $this->provisioner->create(
            (string) $body['organizationId'],
            (string) $body['name'],
            (string) $body['environment'],
            (string) $body['preset'],
        )]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/applications/attach',
        name: 'api.action.fastmon_collector.applications.attach',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function attachApplication(Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $this->guard(fn (): array => ['application' => $this->provisioner->attach(
            (string) $body['organizationId'],
            (string) $body['applicationId'],
        )]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/applications/refresh',
        name: 'api.action.fastmon_collector.applications.refresh',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function refreshApplication(): JsonResponse
    {
        return $this->guard(fn (): array => ['application' => $this->provisioner->refresh()]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/sites',
        name: 'api.action.fastmon_collector.sites',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function sites(): JsonResponse
    {
        // The domains fastmon has actually seen. With site_policy: auto they appear on
        // their own, so an empty list on a live shop is the clearest signal there is that
        // the snippet is reaching nobody.
        return $this->guard(fn (): array => ['sites' => $this->provisioner->sites()]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/collection',
        name: 'api.action.fastmon_collector.collection',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function collectionStatus(Request $request): JsonResponse
    {
        // The probe reaches out to real origins, so it runs when the merchant asks and
        // against the mode they are considering - not the one currently stored.
        $probe = CollectionMode::tryFrom((string) $request->query->get('check', ''));
        $domain = (string) $request->query->get('domain', '');

        return $this->guard(fn (): array => $this->collection->describe($probe, $domain));
    }

    #[Route(
        path: '/api/_action/fastmon-collector/collection',
        name: 'api.action.fastmon_collector.collection.apply',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function applyCollectionMode(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $mode = CollectionMode::tryFrom((string) $body['mode']);

        if ($mode === null) {
            return $this->error('Unknown collection mode.');
        }

        return $this->guard(function () use ($mode, $body): array {
            $this->collection->apply($mode, (string) $body['domain']);

            return [];
        });
    }

    #[Route(
        path: '/api/_action/fastmon-collector/collection/secret',
        name: 'api.action.fastmon_collector.collection.secret',
        defaults: self::WRITE,
        methods: [Request::METHOD_POST]
    )]
    public function generateProxySecret(): JsonResponse
    {
        // fastmon returns the secret exactly once, here. It goes straight to the screen
        // for the merchant to paste into their proxy configuration and is not stored.
        return $this->guard(fn (): array => ['proxySecret' => $this->collection->generateProxySecret()]);
    }

    #[Route(
        path: '/api/_action/fastmon-collector/server-timing',
        name: 'api.action.fastmon_collector.server_timing',
        defaults: self::READ,
        methods: [Request::METHOD_GET]
    )]
    public function serverTiming(): JsonResponse
    {
        // The layers reported are the ones measured for *this* request, which is what
        // makes the panel a sample of the machine rather than a list of possibilities.
        return $this->ok($this->serverTimingStatus->describe());
    }

    /**
     * Run an action and turn the failures a merchant is meant to read into a body.
     *
     * @param callable(): array<string, mixed> $action
     */
    private function guard(callable $action): JsonResponse
    {
        try {
            return $this->ok($action());
        } catch (FastmonUnauthorizedException $e) {
            return $this->error($e->getMessage(), Response::HTTP_OK, ['reconnect' => true]);
        } catch (FastmonOrganizationNotApprovedException $e) {
            // A waiting state, not a fault: the module renders it as such rather than as
            // a red error beside a button the merchant would now press again. The token
            // is untouched - it is valid, the organization simply is not cleared yet.
            return $this->error($e->getMessage(), Response::HTTP_OK, ['pendingApproval' => true]);
        } catch (CollectionNotReadyException $e) {
            // No message of our own: the administration renders the per-origin reasons,
            // translated, from the results below.
            return $this->error('', Response::HTTP_OK, [
                'notReady' => true,
                'domains' => $e->toArray(),
            ]);
        } catch (FastmonPermissionDeniedException $e) {
            // Naming the missing permission is what turns this from a support ticket into
            // something the merchant can fix in the fastmon dashboard themselves.
            return $this->error($e->getMessage(), Response::HTTP_OK, ['permission' => $e->permission]);
        } catch (FastmonApiException | \InvalidArgumentException | \RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ok(array $payload): JsonResponse
    {
        return new JsonResponse(['success' => true] + $payload);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function error(string $message, int $status = Response::HTTP_OK, array $extra = []): JsonResponse
    {
        // Deliberately 200. These are outcomes the module renders inline next to the
        // button that caused them; a 4xx would be swallowed by the administration's
        // global error handler and shown as a toast with no context.
        return new JsonResponse(['success' => false, 'error' => $message] + $extra, $status);
    }

    /**
     * The JSON body, with every key this controller reads present as a string.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return (\is_array($decoded) ? $decoded : []) + [
            'handle' => '',
            'token' => '',
            'organizationId' => '',
            'applicationId' => '',
            'mode' => '',
            'domain' => '',
            'name' => '',
            'environment' => '',
            'preset' => '',
        ];
    }
}
