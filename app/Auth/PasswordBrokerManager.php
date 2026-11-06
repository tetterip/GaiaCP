<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager as Base;

/** Password reset tokens whose created_at is read as UTC, like every time in the database (UtcTokenRepository). */
class PasswordBrokerManager extends Base
{
    protected function createTokenRepository(array $config)
    {
        if (($config['driver'] ?? null) === 'cache') {
            return parent::createTokenRepository($config);
        }

        $key = $this->app['config']['app.key'];

        return new UtcTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key,
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
        );
    }
}
