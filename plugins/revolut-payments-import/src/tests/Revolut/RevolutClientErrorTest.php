<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Tests\Revolut;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RevolutPaymentsImport\Revolut\RevolutApiException;
use RevolutPaymentsImport\Revolut\RevolutClient;

final class RevolutClientErrorTest extends TestCase
{
    private const TOKEN = 'oa_prod_supersecrettoken_abcdef0123456789';

    private function client(MockHandler $mock): RevolutClient
    {
        return new RevolutClient(new Client(['handler' => HandlerStack::create($mock)]), 'production', self::TOKEN);
    }

    public function testUnauthorizedMapsToAuthFailureWithoutLeakingTokenOrQuery(): void
    {
        $mock = new MockHandler([
            new Response(401, [], '{"error_id":"x","code":1000,"message":"Access token is invalid or has expired."}'),
        ]);

        try {
            $this->client($mock)->getJson('/api/1.0/transactions', ['from' => '2026-09-01T00:00:00Z']);
            self::fail('expected RevolutApiException');
        } catch (RevolutApiException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame(1000, $e->revolutCode);
            self::assertTrue($e->isAuthFailure());
            self::assertFalse($e->isTransient());
            self::assertSame('GET', $e->method);
            self::assertSame('/api/1.0/transactions', $e->path);
            self::assertStringContainsString('GET /api/1.0/transactions', $e->getMessage());
            self::assertStringContainsString('Access token is invalid', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringNotContainsString('?from=', $e->getMessage());
        }
    }

    public function testRateLimitedCarriesRetryAfterAndCode(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '30'], '{"code":3162,"message":"Too many requests"}'),
        ]);

        try {
            $this->client($mock)->getJson('/api/1.0/transactions');
            self::fail('expected RevolutApiException');
        } catch (RevolutApiException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertTrue($e->isRateLimited());
            self::assertSame(30, $e->retryAfter);
            self::assertSame(3162, $e->revolutCode);
        }
    }

    public function testServerErrorIsTransientNotAuth(): void
    {
        $mock = new MockHandler([
            new Response(500, [], '{"code":5000,"message":"An unexpected error occurred while processing your request."}'),
        ]);

        try {
            $this->client($mock)->getJson('/api/2.0/webhooks/abc/failed-events', ['limit' => 1000]);
            self::fail('expected RevolutApiException');
        } catch (RevolutApiException $e) {
            self::assertSame(500, $e->statusCode);
            self::assertTrue($e->isTransient());
            self::assertFalse($e->isAuthFailure());
            self::assertFalse($e->isRateLimited());
        }
    }

    public function testEmptyErrorBodyDoesNotLeakQueryString(): void
    {
        // A gateway 401 with no body is common; the message must fall back to the
        // reason phrase, never to Guzzle's message (which embeds the full URL+query).
        $mock = new MockHandler([new Response(401, [], '')]);

        try {
            $this->client($mock)->getJson('/api/1.0/transactions', ['from' => '2026-09-01T00:00:00Z&secret=x']);
            self::fail('expected RevolutApiException');
        } catch (RevolutApiException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertStringNotContainsString('from=', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
            self::assertStringNotContainsString('b2b.revolut.com', $e->getMessage());
            self::assertStringContainsString('GET /api/1.0/transactions', $e->getMessage());
        }
    }

    public function testConnectionFailureHasNullStatusAndIsTransient(): void
    {
        $mock = new MockHandler([
            new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'https://b2b.revolut.com/api/1.0/accounts')),
        ]);

        try {
            $this->client($mock)->getJson('/api/1.0/accounts');
            self::fail('expected RevolutApiException');
        } catch (RevolutApiException $e) {
            self::assertNull($e->statusCode);
            self::assertTrue($e->isTransient());
            self::assertStringContainsString('unreachable', $e->getMessage());
            self::assertStringContainsString('GET /api/1.0/accounts', $e->getMessage());
        }
    }
}
