<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ? "{$title} — " . config('app.name', 'BDMS') : "{$pageTitle} — " . config('app.name', 'BDMS') }}</title>

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
    <body
        x-data="{ sidebarOpen: false }"
        class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100"
    >
        <div class="flex min-h-screen">
            {{-- Mobile slide-over backdrop --}}
            <div
                x-show="sidebarOpen"
                x-cloak
                x-transition.opacity.duration.200ms
                @click="sidebarOpen = false"
                class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-xs lg:hidden"
            ></div>

            {{-- Mobile drawer sidebar --}}
            <aside
                x-show="sidebarOpen"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="-translate-x-full"
                x-transition:enter-end="translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-x-0"
                x-transition:leave-end="-translate-x-full"
                class="fixed inset-y-0 left-0 z-50 w-72 border-r border-slate-200 dark:border-slate-800 lg:hidden"
            >
                @include('partials.sidebar', ['activeRole' => $activeRole])
            </aside>

            {{-- Desktop fixed sidebar --}}
            <aside class="hidden w-68 shrink-0 border-r border-slate-200/80 dark:border-slate-800 lg:block">
                <div class="sticky top-0 h-screen">
                    @include('partials.sidebar', ['activeRole' => $activeRole])
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col">
                @include('partials.topbar', [
                    'activeRole' => $activeRole,
                    'pageTitle' => $pageTitle,
                ])

                <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        @include('partials.flash')

                        @isset($header)
                            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                {{ $header }}
                            </div>
                        @endisset

                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </body>
</html>
