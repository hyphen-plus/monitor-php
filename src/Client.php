<?php

namespace AllClearly\Monitor;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * ส่ง security event เข้า AllClearly Monitoring (POST /api/ingest/events)
 * เก็บ event ไว้ในหน่วยความจำแล้วส่งทีเดียวตอนจบ request (หรือเมื่อครบ maxBatch)
 * monitoring ต้องไม่ทำให้แอปช้าหรือพัง: ไม่ throw ทุก error ส่งให้ onError
 */
class Client
{
    public const TYPES = ['http.request', 'auth.login_failed', 'auth.login_succeeded', 'auth.signup', 'auth.role_changed', 'app.error'];

    private const API_MAX_BATCH = 500;

    private const DEFAULT_ENDPOINT = 'https://allclearly.com';

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private readonly ?string $token;

    private readonly string $url;

    private readonly int $maxBatch;

    private readonly float $timeout;

    private readonly Transport $transport;

    private readonly ?Closure $onError;

    /** 401/402: token ใช้ไม่ได้หรือแพ็กเกจไม่มี Monitoring หยุดส่งตลอดอายุ process */
    private bool $stopped = false;

    private bool $shutdownRegistered = false;

    /**
     * @param  string|null  $token  ไม่ใส่ = อ่าน ALLCLEARLY_MONITOR_TOKEN; ไม่มีทั้งคู่ = ไม่ส่งอะไรเลย
     * @param  callable(Throwable): void|null  $onError
     */
    public function __construct(
        ?string $token = null,
        ?string $endpoint = null,
        int $maxBatch = self::API_MAX_BATCH,
        float $timeout = 2.0,
        ?Transport $transport = null,
        ?callable $onError = null,
        private readonly bool $flushOnShutdown = true,
    ) {
        $token ??= self::env('ALLCLEARLY_MONITOR_TOKEN');
        $this->token = $token === '' ? null : $token;
        $this->url = rtrim($endpoint ?? self::env('ALLCLEARLY_ENDPOINT') ?? self::DEFAULT_ENDPOINT, '/').'/api/ingest/events';
        $this->maxBatch = max(1, min($maxBatch, self::API_MAX_BATCH));
        $this->timeout = $timeout;
        $this->transport = $transport ?? new HttpTransport;
        $this->onError = $onError === null ? null : Closure::fromCallable($onError);
    }

    public function enabled(): bool
    {
        return $this->token !== null && ! $this->stopped;
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    /** @param  array<string, mixed>  $event  type บังคับ ts ไม่ใส่ = ตอนนี้ */
    public function track(array $event): void
    {
        if (! $this->enabled()) {
            return;
        }
        $event['ts'] ??= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $this->queue[] = array_filter($event, fn ($value) => $value !== null);

        if ($this->flushOnShutdown && ! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn () => $this->flush());
        }
        if (count($this->queue) >= $this->maxBatch) {
            $this->flush();
        }
    }

    /** @param  array<string, mixed>  $fields */
    public function loginFailed(array $fields = []): void
    {
        $this->track(['type' => 'auth.login_failed'] + $fields);
    }

    /** @param  array<string, mixed>  $fields */
    public function loginSucceeded(array $fields = []): void
    {
        $this->track(['type' => 'auth.login_succeeded'] + $fields);
    }

    /** @param  array<string, mixed>  $fields */
    public function signup(array $fields = []): void
    {
        $this->track(['type' => 'auth.signup'] + $fields);
    }

    /** @param  array<string, mixed>  $fields */
    public function roleChanged(array $fields = []): void
    {
        $this->track(['type' => 'auth.role_changed'] + $fields);
    }

    /**
     * error ที่ไม่ได้ handle ส่งแค่ชื่อ class ไม่ส่ง message (อาจมีข้อมูลส่วนตัว)
     *
     * @param  array<string, mixed>  $fields
     */
    public function error(?Throwable $error = null, array $fields = []): void
    {
        if ($error !== null) {
            $fields['meta'] = ['error' => (new \ReflectionClass($error))->getShortName()] + (array) ($fields['meta'] ?? []);
        }
        $this->track(['type' => 'app.error'] + $fields);
    }

    /**
     * ส่ง event ที่ค้างทั้งหมด ไม่ throw; ส่งไม่ได้ (เน็ตหลุด, 429, 5xx) = ทิ้ง เพราะ PHP ไม่มี process ค้างไว้ส่งซ้ำ
     *
     * @return array{accepted: int, rejected: int}
     */
    public function flush(): array
    {
        $total = ['accepted' => 0, 'rejected' => 0];
        while ($this->enabled() && $this->queue !== []) {
            $batch = array_splice($this->queue, 0, $this->maxBatch);
            $result = $this->send($batch);
            $total['accepted'] += $result['accepted'];
            $total['rejected'] += $result['rejected'];
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     * @return array{accepted: int, rejected: int}
     */
    private function send(array $batch): array
    {
        $none = ['accepted' => 0, 'rejected' => 0];
        try {
            $response = $this->transport->post($this->url, [
                'Authorization' => "Bearer {$this->token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], json_encode(['events' => $batch], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->timeout);
        } catch (Throwable $e) {
            $this->report('AllClearly send failed: '.$e->getMessage(), $e);

            return $none;
        }

        $status = $response['status'];
        if ($status === 202) {
            $body = json_decode($response['body'], true);
            $result = ['accepted' => (int) ($body['accepted'] ?? count($batch)), 'rejected' => (int) ($body['rejected'] ?? 0)];
            if ($result['rejected'] > 0) {
                $this->report("AllClearly rejected {$result['rejected']} of ".count($batch).' events (check type, ts and ip)');
            }

            return $result;
        }
        if ($status === 401 || $status === 402) {
            $this->stopped = true;
            $this->queue = [];
            $this->report($status === 401
                ? 'AllClearly token is invalid or was rotated; monitoring stopped'
                : 'This AllClearly workspace has no Monitoring in its plan; monitoring stopped');

            return $none;
        }
        $this->report($status === 0
            ? 'AllClearly unreachable; '.count($batch).' events dropped'
            : "AllClearly answered HTTP {$status}; ".count($batch).' events dropped');

        return $none;
    }

    private function report(string $message, ?Throwable $previous = null): void
    {
        if ($this->onError === null) {
            return;
        }
        try {
            ($this->onError)(new MonitorException($message, 0, $previous));
        } catch (Throwable) {
            // onError ของผู้ใช้พังเองก็ต้องไม่กระทบแอป
        }
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
