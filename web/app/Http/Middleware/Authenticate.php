<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        return route('login', array_filter([
        'shop' => $request->query('shop'),
        'host' => $request->query('host'),
        'embedded' => $request->query('embedded'),
        ], fn ($value) => is_string($value) && $value !== ''));
    }
}
