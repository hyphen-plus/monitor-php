<?php

namespace AllClearly\Monitor\Laravel;

use AllClearly\Monitor\RequestInfo;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/** ip, country, method, path ของ request ปัจจุบันในรูปแบบฟิลด์ของ event */
final class RequestFields
{
    /**
     * ฟิลด์ของ request ที่กำลังทำงาน ว่างถ้าเป็นคำสั่ง artisan/queue (request ปลอมของ console ไม่ใช่ผู้ใช้จริง)
     * Octane รันใน console แต่รับ request จริง จึงดูจาก LARAVEL_OCTANE
     *
     * @return array<string, string>
     */
    public static function current(Application $app): array
    {
        $console = $app->runningInConsole() && empty($_SERVER['LARAVEL_OCTANE']) && empty($_ENV['LARAVEL_OCTANE']);

        return ! $console && $app->bound('request') ? self::from($app->make('request')) : [];
    }

    /** @return array<string, string> */
    public static function from(Request $request): array
    {
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());

        return array_filter([
            'ip' => RequestInfo::clientIp($headers, $request->ip(), (bool) config('allclearly.trust_proxy_headers', true)),
            'country' => RequestInfo::country($headers),
            'method' => $request->getMethod(),
            'path' => RequestInfo::path($request->getRequestUri()),
        ], fn ($value) => $value !== null);
    }
}
