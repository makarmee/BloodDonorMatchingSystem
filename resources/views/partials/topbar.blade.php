@props([
    'activeRole' => null,
    'pageTitle' => 'Dashboard',
])

@php
    $user = auth()->user();
    $resolvedRole = $user?->primaryRoleSlug();
    $roleLabels = [
        'admin' => 'Administrator',
        'staff' => 'Blood Bank Staff',
        'lab_officer' => 'Lab Officer',
        'hospital' => 'Hospital Partner',
        'donor' => 'Donor',
    ];
    $roleLabel = $resolvedRole
        ? ($roleLabels[$resolvedRole] ?? ucfirst(str_replace('_', ' ', $resolvedRole)))
        : 'Authenticated User';
    $userName = $user?->name ?? 'Authenticated User';
    $userInitials = strtoupper(substr(preg_replace('/\s+/', '', $userName) ?: 'BD', 0, 2));
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
        <x-ui.theme-toggle />

        <div x-data="{ open: false }" class="relative">
            <button
                type="button"
                @click="open = !open"
                class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
            >
                <span class="flex size-6 items-center justify-center rounded-full bg-crimson-100 text-xs font-bold text-crimson-700 dark:bg-crimson-950 dark:text-crimson-300">
                    {{ $userInitials }}
                </span>
                <span class="hidden sm:inline max-w-32 truncate">
                    {{ $userName }}
                </span>
                <x-icons.icon name="chevron-down" class="size-3.5 text-slate-400" />
            </button>

            <div
                x-show="open"
                x-cloak
                @click.outside="open = false"
                x-transition
                class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-800 dark:bg-slate-900"
            >
                <div class="px-3 py-2 border-b border-slate-100 dark:border-slate-800">
                    <p class="text-xs font-semibold text-slate-900 dark:text-white truncate">
                        {{ $userName }}
                    </p>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Role: {{ $roleLabel }}
                    </p>
                </div>

                <a
                    href="{{ route('home') }}"
                    class="mt-1 flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <x-icons.icon name="droplet" class="size-4 text-crimson-600 dark:text-crimson-400" />
                    Public Home
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        type="submit"
                        class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                    >
                        <x-icons.icon name="arrow-right-on-rectangle" class="size-4" />
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

