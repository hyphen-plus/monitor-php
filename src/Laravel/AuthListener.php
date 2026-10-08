<?php

namespace AllClearly\Monitor\Laravel;

use AllClearly\Monitor\Client;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** แปลง event ของ Laravel Auth เป็น event ของ AllClearly (ไม่ส่งรหัสผ่านหรือ credential อื่นนอกจากชื่อผู้ใช้) */
class AuthListener
{
    /** ชื่อ field ที่ใช้เป็นชื่อผู้ใช้ตอน login ไม่สำเร็จ ตามลำดับ */
    private const USER_FIELDS = ['email', 'username', 'login', 'phone', 'name'];

    public function __construct(private readonly Client $client, private readonly Application $app) {}

    public function failed(Failed $event): void
    {
        $user = null;
        foreach (self::USER_FIELDS as $field) {
            if (is_string($event->credentials[$field] ?? null) && $event->credentials[$field] !== '') {
                $user = $event->credentials[$field];
                break;
            }
        }
        $this->send(['type' => 'auth.login_failed', 'user' => $user ?? ($event->user ? $this->identify($event->user) : null), 'status' => 401]);
    }

    public function login(Login $event): void
    {
        $this->send(['type' => 'auth.login_succeeded', 'user' => $this->identify($event->user), 'admin' => $this->isAdmin($event->user)]);
    }

    public function registered(Registered $event): void
    {
        $this->send(['type' => 'auth.signup', 'user' => $this->identify($event->user)]);
    }

    /** @param  array<string, mixed>  $event */
    private function send(array $event): void
    {
        try {
            $this->client->track($event + RequestFields::current($this->app));
        } catch (Throwable) {
            // monitoring ต้องไม่ทำให้การ login พัง
        }
    }

    private function identify(Authenticatable $user): string
    {
        $email = $user->email ?? null;

        return is_string($email) && $email !== '' ? $email : (string) $user->getAuthIdentifier();
    }

    private function isAdmin(Authenticatable $user): ?bool
    {
        $ability = config('allclearly.admin_ability');

        return is_string($ability) && $ability !== '' ? Gate::forUser($user)->allows($ability) : null;
    }
}
