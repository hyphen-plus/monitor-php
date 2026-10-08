<?php

namespace AllClearly\Monitor;

/** ดึง ip, country, method, path ของ request จาก $_SERVER (PHP ทั่วไป) หรือ header ที่ framework ให้มา */
final class RequestInfo
{
    /**
     * ฟิลด์ของ event จาก $_SERVER: `$monitor->loginFailed(RequestInfo::fromGlobals() + ['user' => $email])`
     *
     * @param  array<string, mixed>|null  $server
     * @param  bool  $trustProxy  true = เชื่อ CF-Connecting-IP / X-Forwarded-For ตั้ง false ถ้าเว็บไม่ได้อยู่หลัง proxy
     * @return array{ip?: string, country?: string, method?: string, path: string}
     */
    public static function fromGlobals(?array $server = null, bool $trustProxy = true): array
    {
        $server ??= $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return array_filter([
            'ip' => self::clientIp($headers, is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : null, $trustProxy),
            'country' => self::country($headers),
            'method' => is_string($server['REQUEST_METHOD'] ?? null) ? strtoupper($server['REQUEST_METHOD']) : null,
            'path' => self::path(is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/'),
        ], fn ($value) => $value !== null);
    }

    /**
     * IP จริง: CF-Connecting-IP > X-Real-IP > X-Forwarded-For ตัวแรก > IP ที่ต่อเข้ามา
     *
     * @param  array<string, string>  $headers  ชื่อ header ตัวเล็ก
     */
    public static function clientIp(array $headers, ?string $remoteIp, bool $trustProxy = true): ?string
    {
        if ($trustProxy) {
            $forwarded = isset($headers['x-forwarded-for']) ? explode(',', $headers['x-forwarded-for'])[0] : null;
            foreach ([$headers['cf-connecting-ip'] ?? null, $headers['x-real-ip'] ?? null, $forwarded] as $candidate) {
                if (($ip = self::validIp($candidate)) !== null) {
                    return $ip;
                }
            }
        }

        return self::validIp($remoteIp);
    }

    /** @param  array<string, string>  $headers  ชื่อ header ตัวเล็ก */
    public static function country(array $headers): ?string
    {
        $value = $headers['cf-ipcountry'] ?? $headers['x-vercel-ip-country'] ?? $headers['cloudfront-viewer-country'] ?? null;

        return is_string($value) && preg_match('/^[A-Za-z]{2}$/', $value) && strtoupper($value) !== 'XX' ? strtoupper($value) : null;
    }

    /** path ไม่มี query string (query อาจมี token หรือข้อมูลส่วนตัว) */
    public static function path(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? mb_substr($path, 0, 500) : '/';
    }

    /** IP ผิดรูปแบบทำให้ event ทั้งตัวถูก reject จึงตัดทิ้ง */
    public static function validIp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $ip = preg_replace('/^::ffff:(?=\d+\.)/i', '', trim($value));

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
