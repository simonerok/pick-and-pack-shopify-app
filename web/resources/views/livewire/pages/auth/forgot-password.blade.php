<?php

use App\Models\User;
use App\Notifications\ForgotPasswordNotification;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::query()
            ->where('email', $this->email)
            ->first();

        $properties = [
            'email' => $this->email,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        if ($user !== null && !$user->trashed()) {
            $token = Password::broker()->createToken($user);
            $user->notify(new ForgotPasswordNotification($token));

            $properties['outcome'] = 'link_sent';
            $properties['name'] = $user->name;
            $properties['role'] = $user->role;
            $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
            activity('auth')
                ->performedOn($user)
                ->event('password_reset_link_requested')
                ->withProperties($properties)
                ->log('Password reset link sent for email: ' . $this->email);
        } else {
            $properties['outcome'] = 'not_found_or_deactivated';
            $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
            activity('auth')
                ->event('password_reset_link_requested')
                ->withProperties($properties)
                ->log('Password reset link requested for unknown or deactivated email: ' . $this->email);
        }

        $this->reset('email');
        session()->flash('status', __('If the email exists in our system, a password reset link has been sent.'));
        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <h1 class="flex justify-center mb-6 text-2xl font-light">{{ __('Forgot Password?') }}</h1>
    <p class="text-slate-500 text-xs mb-6 text-center">{{ __('Enter your email and we will send you a password reset link.') }}</p> 
    {{-- <div class="mb-4 text-sm text-gray-600">
        {{ __('Forgot your password? Enter your email and we will send you a password reset link.') }}
    </div>       --}}

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Send Reset Link') }}
            </x-primary-button>
        </div>
    </form>
</div>
