<?php

namespace AllClearly\Monitor\Laravel;

use AllClearly\Monitor\Client;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** ติดตั้งแล้วใช้ได้เลย: ตั้ง ALLCLEARLY_MONITOR_TOKEN แล้ว request, login และ error จะถูกส่งอัตโนมัติ */
class MonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/allclearly.php', 'allclearly');

        $this->app->singleton(Client::class, function ($app) {
            $config = $app['config']['allclearly'];
            $warned = false;

            return new Client(
                token: $config['token'] ?: null,
                endpoint: $config['endpoint'] ?? null,
                timeout: (float) ($config['timeout'] ?? 2.0),
                // ส่งเองตอน terminating ด้านล่าง (ทำงานกับ Octane ด้วย ซึ่ง shutdown function ไม่ถูกเรียกทุก request)
                onError: function (Throwable $e) use (&$warned) {
                    if (! $warned) {
                        $warned = true;
                        Log::warning($e->getMessage());
                    }
                },
                flushOnShutdown: false,
            );
        });
        $this->app->alias(Client::class, 'allclearly');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/allclearly.php' => config_path('allclearly.php')], 'allclearly-config');
        }

        $client = fn (): Client => $this->app->make(Client::class);
        $this->app->terminating(fn () => $client()->flush());

        if (! config('allclearly.token')) {
            return;
        }

        if (config('allclearly.track_requests') && $this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TrackRequests::class);
            }
        }

        if (config('allclearly.track_auth')) {
            Event::listen(Failed::class, [AuthListener::class, 'failed']);
            Event::listen(Login::class, [AuthListener::class, 'login']);
            Event::listen(Registered::class, [AuthListener::class, 'registered']);
        }

        if (config('allclearly.track_errors')) {
            $handler = $this->app->make(ExceptionHandler::class);
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) use ($client) {
                    if (! $e instanceof HttpExceptionInterface) {
                        $client()->error($e, RequestFields::current($this->app));
                    }
                });
            }
        }
    }
}
