<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ? "{$title} — " . config('app.name', 'BDMS') : config('app.name', 'BDMS') . ' — Blood Donation Management System' }}</title>

        <script>
            (function () {
                const saved = localStorage.getItem('bdms-theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (saved === 'dark' || (!saved && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen flex flex-col bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        @if (! $minimal)
            @include('partials.navbar')
        @endif

        <main class="flex-1">
            @if (session('success') || session('status') || session('warning') || session('error') || $errors->any())
                <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                    @include('partials.flash')
                </div>
            @endif

            {{ $slot }}
        </main>

        @if (! $minimal)
            <footer class="border-t border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-4 px-4 py-8 text-xs text-slate-500 sm:flex-row sm:px-6 lg:px-8 dark:text-slate-400">
                    <div class="flex items-center gap-2">
                        <span class="flex size-6 items-center justify-center rounded-md bg-crimson-600 text-white">
                            <x-icons.icon name="droplet" class="size-3.5" />
                        </span>
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ config('app.name', 'BDMS') }}</span>
                        <span>— Blood Donation Management System</span>
                    </div>

                    <div class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2">
                        <a href="{{ route('public.stock') }}" class="hover:text-slate-900 dark:hover:text-white">Blood Stock</a>
                        <a href="{{ route('public.camps.index') }}" class="hover:text-slate-900 dark:hover:text-white">Donation Camps</a>
                        <a href="{{ route('donor-portal.dashboard') }}" class="hover:text-slate-900 dark:hover:text-white">Donor Portal</a>
                        <a href="{{ route('home') }}#faq-contact" class="hover:text-slate-900 dark:hover:text-white">FAQ &amp; Contact</a>
                        <a href="{{ route('login') }}" class="hover:text-slate-900 dark:hover:text-white">Sign in</a>
                        <a href="{{ route('register') }}" class="hover:text-slate-900 dark:hover:text-white">Register</a>
                        <a href="{{ route('dashboard') }}" class="hover:text-slate-900 dark:hover:text-white">Portal Preview</a>
                    </div>
                </div>
            </footer>
        @endif
    </body>
</html>
