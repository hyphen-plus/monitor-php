<?php

namespace AllClearly\Monitor\Tests;

use AllClearly\Monitor\Client;
use AllClearly\Monitor\Laravel\MonitorServiceProvider;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class LaravelTest extends TestCase
{
    private FakeTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [MonitorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('allclearly.token', 'acm_test');
        $app['config']->set('allclearly.admin_ability', 'admin');
        $this->transport = new FakeTransport;
        $app->singleton(Client::class, fn () => new Client(token: 'acm_test', transport: $this->transport, flushOnShutdown: false));
    }

    protected function defineRoutes($router): void
    {
        Route::get('/hello', fn () => 'hi');
        Route::get('/up', fn () => 'ok');
        Route::get('/boom', fn () => throw new RuntimeException('secret detail'));
    }

    private function user(string $email = 'bob@shop.com'): User
    {
        $user = new User;
        $user->forceFill(['id' => 7, 'email' => $email]);

        return $user;
    }

    public function test_tracks_every_request_after_the_response_and_skips_ignored_paths(): void
    {
        $this->get('/hello?token=secret', ['CF-Connecting-IP' => '203.0.113.9', 'CF-IPCountry' => 'TH'])->assertOk();
        $this->get('/missing')->assertNotFound();
        $this->get('/up')->assertOk();
        $this->app->terminate();

        $events = $this->transport->events();
        $this->assertCount(2, $events);
        $this->assertSame(['http.request', 200, '203.0.113.9', 'TH', 'GET', '/hello'], [$events[0]['type'], $events[0]['status'], $events[0]['ip'], $events[0]['country'], $events[0]['method'], $events[0]['path']]);
        $this->assertSame([404, '/missing'], [$events[1]['status'], $events[1]['path']]);
    }

    public function test_auth_events_become_login_and_signup_events_without_passwords(): void
    {
        Gate::define('admin', fn ($user) => $user->email === 'boss@shop.com');

        event(new Failed('web', null, ['email' => 'bob@shop.com', 'password' => 'hunter2']));
        event(new Login('web', $this->user('boss@shop.com'), false));
        event(new Login('web', $this->user(), false));
        event(new Registered($this->user('new@shop.com')));
        $this->app->make(Client::class)->flush();

        $events = $this->transport->events();
        $this->assertSame(['auth.login_failed', 'auth.login_succeeded', 'auth.login_succeeded', 'auth.signup'], array_column($events, 'type'));
        $this->assertSame('bob@shop.com', $events[0]['user']);
        $this->assertStringNotContainsString('hunter2', json_encode($this->transport->calls));
        $this->assertTrue($events[1]['admin']);
        $this->assertFalse($events[2]['admin']);
        $this->assertSame('new@shop.com', $events[3]['user']);
    }

    public function test_unhandled_exceptions_are_sent_without_their_message(): void
    {
        $this->withExceptionHandling()->get('/boom')->assertStatus(500);
        $this->app->terminate();

        $error = collect($this->transport->events())->firstWhere('type', 'app.error');
        $this->assertSame(['error' => 'RuntimeException'], $error['meta']);
        $this->assertStringNotContainsString('secret detail', json_encode($this->transport->calls));
    }
}
