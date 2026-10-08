<?php

namespace AllClearly\Monitor\Laravel;

use AllClearly\Monitor\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** ส่ง http.request หลังตอบ request แล้ว (terminate) ผู้ใช้จึงไม่ต้องรอ */
class TrackRequests
{
    public function __construct(private readonly Client $client) {}

    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($request->is(...(array) config('allclearly.ignore_paths', []))) {
                return;
            }
            $this->client->track(['type' => 'http.request', 'status' => $response->getStatusCode()] + RequestFields::from($request));
        } catch (Throwable) {
            // monitoring ต้องไม่ทำให้แอปพัง
        }
    }
}
