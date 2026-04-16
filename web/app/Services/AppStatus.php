<?php

namespace App\Services;

class AppStatus
{
    public static function isProduction(): bool
    {
        $raw = trim(strtolower(env('VITE_APP_STATUS', '') ?? ''));

        return $raw === 'production';
    }

    public static function get(): string
    {
        return self::isProduction() ? 'production' : 'test';
    }
}
