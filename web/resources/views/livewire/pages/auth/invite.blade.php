<?php
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        if (auth()->user()->role !== 'super_admin') {
            abort(403);
        }
    }

    public function invite(): void
    {
        if (User::withTrashed()->where('email', $this->email)->exists()) {
            $this->addError('email', __('This email already exists. Check deactivated users.'));
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make(Str::random(32)),
            'role' => 'coworker',
        ]);

        Password::sendResetLink(['email' => $user->email]);

        $properties = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'actor_role' => auth()->user()->role,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
        $properties = \App\Services\IpGeolocationService::addGeoToProperties($properties);
        activity('auth')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->event('invite')
            ->withProperties($properties)
            ->log('Super admin invited coworker');

        session()->flash('status', 'An invite email was sent.');
        $this->redirect(route('home'), navigate: true);
    }
}; ?>


<main class="w-full min-w-0 px-4 py-8 text-sm">
    <div class="flex justify-center mb-6">
        <x-sbb-logo />
    </div>

    <div class="mx-auto max-w-xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">

        {{-- <p class="text-slate-500 text-xs mb-6">{{ __('Create a coworker account and send a password setup email.') }}</p> --}}
        <h1 class="flex justify-center mb-6 text-2xl font-light">{{ __('Invite Coworker') }}</h1>
        <p class="text-slate-500 text-xs mb-6 text-center">{{ __('Create a coworker account and send a password setup email.') }}</p> 

        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form wire:submit="invite" x-on:submit="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Sending invite…' } }))">
            <!-- Name -->
            <div>
                <x-input-label for="name" :value="__('Name')" />
                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" name="name" required autofocus autocomplete="name" />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <!-- Email Address -->
            <div class="mt-4">
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" name="email" required autocomplete="username" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="invite"
                    class="disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span wire:loading.remove wire:target="invite">
                        {{ __('Send Invite') }}
                    </span>
                    <span wire:loading wire:target="invite">
                        {{ __('Sending Invite...') }}
                    </span>
                </x-primary-button>
            </div>
        </form>
    </div>
</main>