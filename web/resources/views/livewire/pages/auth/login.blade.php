<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
{
    $this->validate();
    // logging activity for login (see if it's successful or not)
    try {
        $this->form->authenticate();
    } catch (\Illuminate\Validation\ValidationException $e) {
        $properties = [
            'email' => $this->form->email,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
        $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
        activity('auth')
            ->event('login_failed')
            ->withProperties($properties)
            ->log('Login failed for email: ' . $this->form->email);

        throw $e;
    }

    Session::regenerate();

    $properties = [
        'email' => auth()->user()->email,
        'role' => auth()->user()->role,
        'ip' => request()->ip(),
        'user_agent' => request()->userAgent(),
    ];
    $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);

    // logging activity for login
    activity('auth')
        ->causedBy(auth()->user())
        ->performedOn(auth()->user())
        ->event('login')
        ->withProperties($properties)
        ->log('User logged in');

    $this->redirectIntended(
        default: route('home', array_filter([
            'shop' => request()->query('shop'),
            'host' => request()->query('host'),
            'embedded' => request()->query('embedded'),
        ], fn ($value) => is_string($value) && $value !== ''), absolute: false)
    );
}
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</div>
