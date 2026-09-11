<?php declare(strict_types=1);

namespace Fastmon\Collector\Api;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reading fastmon's JSON without trusting its shape.
 *
 * Shared by the two clients because both talk to the same server and neither may assume
 * a field is there or is of the type it was yesterday: a response is a foreign document,
 * and every reader narrows what it takes. A missing or wrongly typed value becomes an
 * empty string or the caller's default, which every caller already has to handle for the
 * genuinely optional fields.
 */
trait ReadsJsonResponses
{
    /**
     * @return array<mixed> keys and values are whatever fastmon sent
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new FastmonApiException('fastmon returned a response that is not JSON', 0, $e);
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
