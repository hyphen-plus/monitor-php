<?php

namespace AllClearly\Monitor\Tests;

use AllClearly\Monitor\Transport;

/** ตอบตามลำดับใน $responses (ตัวสุดท้ายใช้ซ้ำ) และจำทุก request */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $calls = [];

    /** @param  list<array{status: int, body?: string}>  $responses */
    public function __construct(private array $responses = [['status' => 202, 'body' => '{"accepted":1,"rejected":0}']]) {}

    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        $next = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

        return ['status' => $next['status'], 'body' => $next['body'] ?? ''];
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return array_merge(...array_map(fn ($call) => $call['body']['events'], $this->calls));
    }
}
