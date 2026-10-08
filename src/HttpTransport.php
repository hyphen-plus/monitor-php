<?php

namespace AllClearly\Monitor;

/** ส่งด้วย cURL ถ้ามี ไม่มีใช้ stream ของ PHP; ตรวจใบรับรอง TLS เสมอ */
final class HttpTransport implements Transport
{
    public function post(string $url, array $headers, string $body, float $timeoutSeconds): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return function_exists('curl_init') ? $this->curl($url, $lines, $body, $timeoutSeconds) : $this->stream($url, $lines, $body, $timeoutSeconds);
    }

    /** @param  list<string>  $headers */
    private function curl(string $url, array $headers, string $body, float $timeout): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 2) * 1000),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $response === false ? 0 : $status, 'body' => is_string($response) ? $response : ''];
    }

    /** @param  list<string>  $headers */
    private function stream(string $url, array $headers, string $body, float $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $status = (int) $match[1];
            }
        }

        return ['status' => $response === false ? 0 : $status, 'body' => is_string($response) ? $response : ''];
    }
}
