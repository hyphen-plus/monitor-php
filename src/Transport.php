<?php

namespace AllClearly\Monitor;

/** ช่องทางส่ง HTTP แยกออกมาเพื่อให้เทสหรือใช้ HTTP client ของแอปเองได้ */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string} status 0 = ติดต่อไม่ได้ (เน็ตหลุด, timeout)
     */
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array;
}
