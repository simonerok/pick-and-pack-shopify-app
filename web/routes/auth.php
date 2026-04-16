<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Illuminate\Http\Request;

Route::middleware('guest')->group(function () {

    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::post('logout', function () {
    $user = auth()->user();
    $properties = [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'ip' => request()->ip(),
        'user_agent' => request()->userAgent(),
    ];
    $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
    activity('auth')
        ->causedBy($user)
        ->performedOn($user)
        ->event('logout')
        ->withProperties($properties)
        ->log('User logged out');

    auth()->guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect()->route('login', array_filter([
        'shop' => request()->query('shop'),
        'host' => request()->query('host'),
        'embedded' => request()->query('embedded'),
    ], fn ($value) => is_string($value) && $value !== ''));
})->middleware('auth')->name('logout');

// SOFT DELETE ACCOUNT
Route::post('delete-account', function (Request $request) {
    $request->validate([
        'password' => ['required', 'string', 'current_password'],
    ]);

    $user = auth()->user();

    $properties = [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'delete_type' => 'soft_delete',
    ];
    $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);

    activity('auth')
        ->causedBy($user)
        ->performedOn($user)
        ->event('account_deactivated')
        ->withProperties($properties)
        ->log('User deactivated account');

    $user->delete(); // soft delete
    auth()->guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login', array_filter([
        'shop' => $request->query('shop'),
        'host' => $request->query('host'),
        'embedded' => $request->query('embedded'),
    ], fn ($value) => is_string($value) && $value !== ''));
})->middleware('auth')->name('delete-account');

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');

    // INVITE COWORKER
    Volt::route('invite', 'pages.auth.invite')
    ->name('invite');

    // SEE LIST OF COWORKERS
    Volt::route('coworkers', 'pages.auth.coworkers')
    ->name('coworkers');
});
