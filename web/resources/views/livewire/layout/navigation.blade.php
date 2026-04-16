<?php

use Livewire\Volt\Component;
use App\Services\AppStatus;


new class extends Component
{
    public string $appStatus = 'test';

    public function mount(): void
    {
        $this->appStatus = AppStatus::get();
    }

}; ?>


<nav x-data="{ open: false }" class=" border-b border-gray-100">
    <!-- Primary Navigation Menu -->
    <div class="w-full min-w-0 px-4 text-sm align-end">
        <div class="flex h-16 justify-end">
            <div class="flex">
                <!-- Logo -->
                {{-- Show app status --}}
                <div class="shrink-0 flex items-center" role="status" aria-label="App status: {{ $appStatus }}">
                    @if($appStatus === 'production')
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-100 text-emerald-800">Production</span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-600">Test</span>
                    @endif
                </div>
                

            <!-- Settings Dropdown -->
            <div class="hidden sm:flex sm:items-center sm:ms-6 align-end">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>
                    <x-slot name="content">

                        <!-- Home -->
                        @if(auth()->user()->role === 'super_admin')
                        <div wire:ignore>
                            <a href="{{ route('home') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading home ...' } }))" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                {{ __('Home') }}
                            </a>
                        </div>
                        @endif

                        <!-- Add coworker -->
                        @if(auth()->user()->role === 'super_admin')
                        <div wire:ignore>
                            <a href="{{ route('invite') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading invite ...' } }))" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                {{ __('Invite Coworker') }}
                            </a>
                        </div>
                        @endif

                        <!-- See list of coworkers -->
                        @if(auth()->user()->role === 'super_admin')
                        <div wire:ignore>
                            <a href="{{ route('coworkers') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading coworkers ...' } }))" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                {{ __('Coworkers List') }}
                            </a>
                        </div>
                        @endif

                        <!-- Delete Account -->
                        <div wire:ignore>
                            <button
                                type="button"
                                x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion-nav')"
                                class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
                            >
                                {{ __('Delete Account') }}
                            </button>
                        </div>
                   
                        <!-- Authentication -->
                        <div wire:ignore>
                            <form method="POST" action="{{ route('logout', array_filter(['shop' => request()->query('shop'), 'host' => request()->query('host'), 'embedded' => request()->query('embedded')], fn ($v) => is_string($v) && $v !== '')) }}" class="block" onsubmit="window.dispatchEvent(new CustomEvent('logout-start')); var m=document.querySelector('meta[name=csrf-token]'); if(m){ var i=this.querySelector('input[name=_token]'); if(i) i.value=m.getAttribute('content'); } return true;">
                                @csrf
                                <button type="submit" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                    {{ __('Log Out') }}
                                </button>
                            </form>
                        </div>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">

        <!-- Responsive Settings Options -->
         <div class="rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 overflow-hidden my-2">
        <div class="pt-4 pb-1 border-t border-gray-200 text-right">
            <div class="px-4 text-right">
                <div class="font-medium text-sm text-gray-800" x-data="{{ json_encode(['name' => auth()->user()->name ?? 'User']) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                
            </div>

            <!-- Mobile Home -->
                        @if(auth()->user()->role === 'super_admin')
                        <div wire:ignore>
                            <a href="{{ route('home') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading home ...' } }))" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out">
                                {{ __('Home') }}
                            </a>
                        </div>
                        @endif

            <!-- Mobile Invite Coworker -->
            @if(auth()->user()->role === 'super_admin')
            <div class="mt-3 space-y-1 text-right">
                <a href="{{ route('invite') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading invite ...' } }))" class="block w-full px-4 py-2 text-right text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 focus:outline-none focus:bg-gray-50 transition duration-150 ease-in-out">
                    {{ __('Invite Coworker') }}
                </a>
            </div>
            @endif

        <!-- Mobile See list of coworkers -->
                @if(auth()->user()->role === 'super_admin')
                <div class="mt-3 space-y-1 text-right">
                    <a href="{{ route('coworkers') }}" wire:navigate x-on:click="window.dispatchEvent(new CustomEvent('page-loading-start', { detail: { message: 'Loading coworkers ...' } }))" class="block w-full px-4 py-2 text-right text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 focus:outline-none focus:bg-gray-50 transition duration-150 ease-in-out">
                        {{ __('Coworkers List') }}
                    </a>
                </div>
                @endif

            <!-- Mobile Delete Account -->
            <div wire:ignore>
                <button
                    type="button"
                    x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion-nav')"
                    class="block w-full px-4 mt-4 text-right text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 focus:outline-none focus:bg-gray-50 transition duration-150 ease-in-out">
                    {{ __('Delete Account') }}
                </button>
            </div>

            <div class="mt-3 space-y-1 text-right">
                <!-- Mobile Authentication -->
                <div wire:ignore>
                    <form method="POST" action="{{ route('logout', array_filter(['shop' => request()->query('shop'), 'host' => request()->query('host'), 'embedded' => request()->query('embedded')], fn ($v) => is_string($v) && $v !== '')) }}" class="block" onsubmit="window.dispatchEvent(new CustomEvent('logout-start')); var m=document.querySelector('meta[name=csrf-token]'); if(m){ var i=this.querySelector('input[name=_token]'); if(i) i.value=m.getAttribute('content'); } return true;">
                        @csrf
                        <button type="submit" class="block w-full px-4 mb-2 text-right text-sm font-medium text-gray-600 hover:text-gray-800 hover:bg-gray-50 focus:outline-none focus:bg-gray-50 transition duration-150 ease-in-out">
                            {{ __('Log Out') }}
                        </button>
                    </form>
                </div>
            </div>
            </div>
        </div>
    </div>
</nav>

{{-- Delete Account Modal --}}
<x-modal name="confirm-user-deletion-nav" :show="$errors->isNotEmpty()" focusable maxWidth="md">
    <form
        x-data="{ deletingAccount: false }"
        method="POST"
        action="{{ route('delete-account', array_filter([
            'shop' => request()->query('shop'),
            'host' => request()->query('host'),
            'embedded' => request()->query('embedded'),
        ], fn ($v) => is_string($v) && $v !== '')) }}"
        class="p-6"
        onsubmit="window.dispatchEvent(new CustomEvent('delete-account-start')); this.__x.$data.deletingAccount = true; return true;"
    >
        @csrf
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Deleting your account will deactivate it. Your account and associated data are retained for record-keeping. Please enter your password to confirm.') }}
        </p>

        <div class="mt-6">
            <input
                type="email"
                name="email"
                value="{{ auth()->user()->email }}"
                autocomplete="username"
                class="sr-only"
                tabindex="-1"
                aria-hidden="true"
            />

            <x-input-label for="delete_password" value="{{ __('Password') }}" class="sr-only" />
            <x-text-input
                id="delete_password"
                name="password"
                type="password"
                class="mt-1 block w-3/4"
                placeholder="{{ __('Password') }}"
                autocomplete="current-password"
                required
                x-bind:disabled="deletingAccount"
            />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-6 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')" x-bind:disabled="deletingAccount">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3 disabled:opacity-60 disabled:cursor-not-allowed" x-bind:disabled="deletingAccount">
                <span x-show="!deletingAccount">{{ __('Delete Account') }}</span>
                <span x-show="deletingAccount">{{ __('Deleting Account...') }}</span>
            </x-danger-button>
        </div>
    </form>
</x-modal>
