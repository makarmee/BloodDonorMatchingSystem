@props([
    'activeRole' => 'staff',
    'pageTitle' => 'Dashboard',
])

@php
    $roles = [
        'staff' => 'Staff / Admin',
        'donor' => 'Donor',
        'hospital' => 'Hospital',
    ];
@endphp

<header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 border-b border-slate-200/80 bg-white/90 px-4 backdrop-blur-md sm:px-6 lg:px-8 dark:border-slate-800/80 dark:bg-slate-900/90">
    <div class="flex items-center gap-3 min-w-0">
        <button
            type="button"
            @click="sidebarOpen = true"
            class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 hover:bg-slate-50 lg:hidden dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 cursor-pointer"
            aria-label="Open sidebar"
        >
            <x-icons.icon name="menu" class="size-5" />
        </button>

        <h1 class="truncate text-base font-semibold text-slate-900 sm:text-lg dark:text-white">
            {{ $pageTitle }}
        </h1>
    </div>

    <div class="flex items-center gap-2 sm:gap-3">
        {{-- Role switcher for previewing the three BDMS portals before backend auth is wired --}}
        <div class="hidden md:flex items-center rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-950" aria-label="Portal preview switcher">
            @foreach ($roles as $key => $label)
                <a
                    href="{{ route('dashboard', ['role' => $key]) }}"
                    @class([
                        'rounded-md px-2.5 py-1 text-xs font-medium transition',
                        'bg-white text-slate-900 shadow-2xs dark:bg-slate-800 dark:text-white' => $activeRole === $key,
                        'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $activeRole !== $key,
                    ])
                >
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div x-data="{ open: false }" class="relative">
            <button
                type="button"
                @click="open = !open"
                class="relative inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white cursor-pointer"
                aria-label="Notifications"
            >
                <x-icons.icon name="bell" class="size-4" />
                <span class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-crimson-600 ring-2 ring-white dark:ring-slate-900"></span>
            </button>

            <div
                x-show="open"
                x-cloak
                @click.outside="open = false"
                x-transition
                class="absolute right-0 mt-2 w-80 rounded-xl border border-slate-200 bg-white p-3 shadow-lg dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="flex items-center justify-between border-b border-slate-200 pb-2 dark:border-slate-800">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Urgent Alerts
                    </span>
                    <x-ui.badge variant="danger">2 Active</x-ui.badge>
                </div>
                <div class="mt-2.5 space-y-2 text-xs">
                    <div class="rounded-lg bg-rose-50/70 p-2.5 dark:bg-rose-950/40">
                        <p class="font-semibold text-rose-800 dark:text-rose-200">O- Stock Below Threshold</p>
                        <p class="mt-0.5 text-rose-700/80 dark:text-rose-300/80">14 units remaining across central bank.</p>
                    </div>
                    <div class="rounded-lg bg-amber-50/70 p-2.5 dark:bg-amber-950/40">
                        <p class="font-semibold text-amber-800 dark:text-amber-200">Emergency Request #REQ-1042</p>
                        <p class="mt-0.5 text-amber-700/80 dark:text-amber-300/80">City General Hospital requested 4 units of AB-.</p>
                    </div>
                </div>
            </div>
        </div>

        <x-ui.theme-toggle />

        <div x-data="{ open: false }" class="relative">
            <button
                type="button"
                @click="open = !open"
                class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
            >
                <span class="flex size-6 items-center justify-center rounded-full bg-crimson-100 text-xs font-bold text-crimson-700 dark:bg-crimson-950 dark:text-crimson-300">
                    {{ strtoupper(substr(auth()->user()?->name ?? 'BD', 0, 2)) }}
                </span>
                <span class="hidden sm:inline max-w-28 truncate">
                    {{ auth()->user()?->name ?? $roles[$activeRole] ?? 'Staff' }}
                </span>
                <x-icons.icon name="chevron-down" class="size-3.5 text-slate-400" />
            </button>

            <div
                x-show="open"
                x-cloak
                @click.outside="open = false"
                x-transition
                class="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-800">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">
                        {{ auth()->user()?->name ?? 'Preview Account' }}
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Role: {{ $roles[$activeRole] ?? 'Staff / Admin' }}
                    </p>
                </div>

                <div class="py-1 md:hidden border-b border-slate-100 dark:border-slate-800">
                    @foreach ($roles as $key => $label)
                        <a
                            href="{{ route('dashboard', ['role' => $key]) }}"
                            class="block rounded-lg px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Switch to {{ $label }}
                        </a>
                    @endforeach
                </div>

                <a
                    href="{{ route('home') }}"
                    class="mt-1 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <x-icons.icon name="droplet" class="size-4 text-crimson-600 dark:text-crimson-400" />
                    Public Home
                </a>
                <a
                    href="{{ route('login') }}"
                    class="flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <x-icons.icon name="arrow-right-on-rectangle" class="size-4" />
                    Sign in screen
                </a>
            </div>
        </div>
    </div>
</header>

