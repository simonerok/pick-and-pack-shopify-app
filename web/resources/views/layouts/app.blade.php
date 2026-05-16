<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="shopify-api-key" content="{{ env('SHOPIFY_API_KEY') }}">
        

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        {{-- App Bridge script removed: in the Shopify admin iframe it interfered with same-origin fetch (same class of issue as guest layout + Livewire). Re-enable only if you add explicit App Bridge usage. --}}
    </head>
    <body
        class="font-sans antialiased"
        x-data="{ loadingOverlay: false, loadingMessage: 'Loading…' }"
        x-on:logout-start.window="loadingOverlay = true; loadingMessage = 'Logging out…'"
        x-on:delete-account-start.window="loadingOverlay = true; loadingMessage = 'Deleting account…'"
        x-on:page-loading-start.window="loadingOverlay = true; loadingMessage = $event.detail?.message ?? 'Loading…'"
    >
                <div x-show="loadingOverlay"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    class="fixed inset-0 z-[9999] flex items-center justify-center bg-white cursor-disabled">
                <div class="flex items-center gap-2 rounded-lg bg-white px-4 py-3"> 
            <span class="inline-block w-5 h-5 border-2 border-slate-400 border-t-transparent rounded-full animate-spin"></span>
            <span class="text-sm text-slate-600" x-text="loadingMessage"></span>
        </div>
    </div>
        <div class="min-h-screen bg-gray-100">
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

             <!-- Page Content -->
            <main>
                @isset($slot)
                    {{ $slot }}
                @else
                    @yield('content')
                @endisset
            </main>

            @hasSection('footer_note')
                <footer class="px-4 py-4 text-center text-xs text-slate-500">
                    @yield('footer_note')
                </footer>
            @endif
        </div>
          @livewireScripts
    </body>
</html>
