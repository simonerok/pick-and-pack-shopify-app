<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Mount the component.
     */
    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    public function updatedPassword(): void
    {
        $this->resetErrorBag('password');
        $this->resetErrorBag('password_confirmation');
    }

    public function updatedPasswordConfirmation(): void
    {
        $this->resetErrorBag('password_confirmation');
    }

    /**
     * Reset the password for the given user.
     */
    public function resetPassword(): void
    {
                    $this->validate([
                        'token' => ['required'],
                        'email' => ['required', 'string', 'email'],
                        'password' => [
                            'required',
                            'string',
                            Rules\Password::min(12)
                                ->mixedCase()
                                ->numbers()
                                ->symbols(),
                        ],
                        'password_confirmation' => ['required', 'string'],
                    ]);

                    if ($this->password !== $this->password_confirmation) {
                        $this->addError('password_confirmation', __('Password and confirm password must match.'));
                        return;
                    }
        

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $resetUser = null;

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use (&$resetUser) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
                $resetUser = $user;
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status != Password::PASSWORD_RESET) {
            $this->addError('email', __($status));

            return;
        }

        Session::flash('status', 'Password was reset successfully.');

        $properties = [
            'email' => $this->email,
            'name' => $resetUser?->name,
            'role' => $resetUser?->role,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
        $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
        $activity = activity('auth')
            ->event('password_reset')
            ->withProperties($properties);

        if ($resetUser !== null) {
            $activity->performedOn($resetUser);
        }

        $activity->log('Password reset for email: ' . $this->email);

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <h1 class="flex justify-center mb-6 text-2xl font-light">{{ __('Reset Password') }}</h1>
    <p class="text-slate-500 text-xs mb-6 text-center">{{ __('Enter your new password below.') }}</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />
    <form
        wire:submit="resetPassword"
        x-data="{
            password: @entangle('password'),
            get hasMinLength() { return this.password.length >= 12 },
            get hasUppercase() { return /[A-Z]/.test(this.password) },
            get hasNumber() { return /[0-9]/.test(this.password) },
            get hasSymbol() { return /[^A-Za-z0-9]/.test(this.password) },
            get passwordValid() { return this.hasMinLength && this.hasUppercase && this.hasNumber && this.hasSymbol }
        }"
    >
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            {{-- @if ($errors->has('password'))
                <p class="mt-2 text-sm text-red-600">
                    Password does not yet meet all requirements below.
                </p>
            @endif --}}
            <p x-show="password.length > 0 && !passwordValid" x-cloak class="mt-2 text-sm text-red-600">
                {{ __('Password does not yet meet all requirements below.') }}  
            </p>
            <ul class="mt-2 text-sm space-y-1">
                <li :class="hasMinLength ? 'text-green-600' : 'text-gray-500'">
                    12+ characters
                </li>
                <li :class="hasUppercase ? 'text-green-600' : 'text-gray-500'">
                    1 uppercase letter
                </li>
                <li :class="hasNumber ? 'text-green-600' : 'text-gray-500'">
                    1 number
                </li>
                <li :class="hasSymbol ? 'text-green-600' : 'text-gray-500'">
                    1 special character
                </li>
            </ul>
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />

            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full"
                          type="password"
                          name="password_confirmation" required autocomplete="new-password"
                          x-bind:disabled="!passwordValid"
                          x-bind:class="!passwordValid ? 'opacity-50 cursor-not-allowed' : ''" />

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            <p x-show="!passwordValid" x-cloak class="mt-2 text-sm text-gray-500">
                Complete all password requirements above to confirm your password.
            </p>
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Reset Password') }}
            </x-primary-button>
        </div>
    </form>
    </div>
</div>
