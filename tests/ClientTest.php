<?php

namespace AllClearly\Monitor\Tests;

use AllClearly\Monitor\Client;
use AllClearly\Monitor\RequestInfo;
use PHPUnit\Framework\TestCase;
use RangeException;
use RuntimeException;

class ClientTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    private function client(FakeTransport $transport, int $maxBatch = 500, ?string $token = 'acm_test'): Client
    {
        return new Client(token: $token, endpoint: 'https://example.test/', maxBatch: $maxBatch, transport: $transport,
            onError: fn ($e) => $this->errors[] = $e->getMessage(), flushOnShutdown: false);
    }

    public function test_sends_buffered_events_with_the_bearer_token_and_fills_ts(): void
    {
        $transport = new FakeTransport([['status' => 202, 'body' => '{"accepted":2,"rejected":0}']]);
        $client = $this->client($transport);
        $client->loginFailed(['ip' => '203.0.113.9', 'user' => 'bob@shop.com', 'country' => null]);
        $client->track(['type' => 'http.request', 'ts' => '2026-10-08T00:00:00Z', 'path' => '/']);

        $this->assertSame(['accepted' => 2, 'rejected' => 0], $client->flush());
        $this->assertCount(1, $transport->calls);
        $this->assertSame('https://example.test/api/ingest/events', $transport->calls[0]['url']);
        $this->assertSame('Bearer acm_test', $transport->calls[0]['headers']['Authorization']);
        [$first, $second] = $transport->events();
        $this->assertSame('auth.login_failed', $first['type']);
        $this->assertArrayNotHasKey('country', $first); // null ไม่ถูกส่ง
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $first['ts']);
        $this->assertSame('2026-10-08T00:00:00Z', $second['ts']);
        $this->assertSame(0, $client->pending());
    }

    public function test_sends_a_batch_as_soon_as_it_is_full(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport, maxBatch: 2);
        foreach (range(1, 5) as $i) {
            $client->track(['type' => 'http.request']);
        }
        $this->assertCount(2, $transport->calls);
        $client->flush();
        $this->assertSame([2, 2, 1], array_map(fn ($call) => count($call['body']['events']), $transport->calls));
    }

    public function test_does_nothing_without_a_token(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport, token: '');
        $client->loginFailed();
        $client->flush();
        $this->assertFalse($client->enabled());
        $this->assertSame([], $transport->calls);
    }

    public function test_stops_for_good_on_401_and_402(): void
    {
        foreach ([401, 402] as $status) {
            $this->errors = [];
            $transport = new FakeTransport([['status' => $status]]);
            $client = $this->client($transport);
            $client->loginFailed();
            $client->flush();
            $client->loginFailed();
            $client->flush();
            $this->assertCount(1, $transport->calls);
            $this->assertFalse($client->enabled());
            $this->assertCount(1, $this->errors);
        }
    }

    public function test_drops_events_it_cannot_deliver_and_reports_it(): void
    {
        $transport = new FakeTransport([['status' => 0], ['status' => 503], ['status' => 202, 'body' => '{"accepted":1,"rejected":1}']]);
        $client = $this->client($transport);
        foreach (range(1, 3) as $i) {
            $client->loginFailed();
            $client->flush();
            $this->assertSame(0, $client->pending());
        }
        $this->assertStringContainsString('unreachable', $this->errors[0]);
        $this->assertStringContainsString('503', $this->errors[1]);
        $this->assertStringContainsString('rejected 1', $this->errors[2]);
    }

    public function test_error_sends_only_the_exception_class_name(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport);
        $client->error(new RangeException('card 4242 declined for bob@shop.com'), ['path' => '/pay']);
        $client->flush();
        $this->assertSame(['error' => 'RangeException'], $transport->events()[0]['meta']);
    }

    public function test_a_throwing_transport_or_on_error_never_escapes(): void
    {
        $transport = new class implements \AllClearly\Monitor\Transport
        {
            public function post(string $url, array $headers, string $body, float $timeoutSeconds): array
            {
                throw new RuntimeException('socket closed');
            }
        };
        $client = new Client(token: 'acm_test', transport: $transport, onError: fn () => throw new RuntimeException('boom'), flushOnShutdown: false);
        $client->loginFailed();
        $this->assertSame(['accepted' => 0, 'rejected' => 0], $client->flush());
    }

    public function test_request_info_from_globals(): void
    {
        $info = RequestInfo::fromGlobals([
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/login?next=/admin&token=secret',
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_CF_IPCOUNTRY' => 'th',
        ]);
        $this->assertSame(['ip' => '203.0.113.9', 'country' => 'TH', 'method' => 'POST', 'path' => '/login'], $info);

        $spoofed = ['REQUEST_URI' => '/', 'REMOTE_ADDR' => '::ffff:10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'nope, 1.2.3.4', 'HTTP_CF_IPCOUNTRY' => 'XX'];
        $this->assertSame(['ip' => '10.0.0.1', 'path' => '/'], RequestInfo::fromGlobals($spoofed));
        $this->assertSame('10.0.0.1', RequestInfo::fromGlobals(['HTTP_CF_CONNECTING_IP' => '1.2.3.4', 'REMOTE_ADDR' => '10.0.0.1'], trustProxy: false)['ip']);
    }
}
