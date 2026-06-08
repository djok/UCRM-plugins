<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Revolut;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP access to the Revolut Business API. Base hosts:
 *   production: https://b2b.revolut.com
 *   sandbox:    https://sandbox-b2b.revolut.com
 * Most endpoints live under /api/1.0; webhooks v2 under /api/2.0.
 */
final class RevolutClient
{
    private const HOSTS = [
        'production' => 'https://b2b.revolut.com',
        'sandbox' => 'https://sandbox-b2b.revolut.com',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $environment,
        private readonly ?string $accessToken = null,
    ) {
    }

    public function host(): string
    {
        return self::HOSTS[$this->environment] ?? self::HOSTS['sandbox'];
    }

    /**
     * @param array<string,scalar> $query
     * @return array<mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $url = $this->host() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->decode($this->http->send($this->authorized('GET', $url)));
    }

    /**
     * @param array<string,mixed> $json
     * @return array<mixed>
     */
    public function postJson(string $path, array $json): array
    {
        $request = $this->authorized(
            'POST',
            $this->host() . $path,
            ['Content-Type' => 'application/json'],
            (string) json_encode($json),
        );

        return $this->decode($this->http->send($request));
    }

    /**
     * POST application/x-www-form-urlencoded (used for the token endpoint,
     * which is unauthenticated — uses the JWT client assertion instead).
     *
     * @param array<string,string> $form
     * @return array<mixed>
     */
    public function postForm(string $path, array $form): array
    {
        $request = new Request(
            'POST',
            $this->host() . $path,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form),
        );

        return $this->decode($this->http->send($request));
    }

    /** @param array<string,string> $extraHeaders */
    private function authorized(string $method, string $url, array $extraHeaders = [], ?string $body = null): Request
    {
        $headers = $extraHeaders;
        if ($this->accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        return new Request($method, $url, $headers, $body);
    }

    /** @return array<mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
