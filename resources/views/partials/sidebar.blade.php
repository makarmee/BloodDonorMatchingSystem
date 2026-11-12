@props([
    'activeRole' => null,
])

@php
    $user = auth()->user();
    $user?->loadMissing('roles.permissions');
    $resolvedRole = $user?->primaryRoleSlug() ?? $activeRole;

    $roleMeta = match ($resolvedRole) {
        'admin' => [
            'label' => 'Administrator',
            'badge' => 'crimson',
            'sections' => [
                'Operations' => [
                    ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'route' => 'dashboard', 'match' => 'dashboard'],
                    ['label' => 'Blood Inventory', 'icon' => 'archive-box', 'route' => 'inventory.index'],
                    ['label' => 'Donors', 'icon' => 'users', 'route' => 'donors.index'],
                    ['label' => 'Donations Log', 'icon' => 'heart-pulse', 'route' => 'donations.index'],
                ],
                'Fulfillment & Screening' => [
                    ['label' => 'Blood Requests', 'icon' => 'clipboard-list', 'route' => 'requests.index'],
                    ['label' => 'Donation Camps', 'icon' => 'calendar', 'route' => 'camps.index'],
                    ['label' => 'Lab Screening', 'icon' => 'shield-check', 'route' => 'screening.index'],
                ],
            ],
        ],
        'staff' => [
            'label' => 'Blood Bank Staff',
            'badge' => 'success',
            'sections' => [
                'Operations' => [
                    ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'route' => 'dashboard', 'match' => 'dashboard'],
                    ['label' => 'Blood Inventory', 'icon' => 'archive-box', 'route' => 'inventory.index'],
                    ['label' => 'Donors', 'icon' => 'users', 'route' => 'donors.index'],
                    ['label' => 'Donations Log', 'icon' => 'heart-pulse', 'route' => 'donations.index'],
                ],
                'Fulfillment & Drives' => [
                    ['label' => 'Blood Requests', 'icon' => 'clipboard-list', 'route' => 'requests.index'],
                    ['label' => 'Donation Camps', 'icon' => 'calendar', 'route' => 'camps.index'],
                ],
            ],
        ],
        'lab_officer' => [
            'label' => 'Lab Officer',
            'badge' => 'warning',
            'sections' => [
                'Laboratory' => [
                    ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'route' => 'dashboard', 'match' => 'dashboard'],
                    ['label' => 'Lab Screening', 'icon' => 'shield-check', 'route' => 'screening.index'],
                    ['label' => 'Donors', 'icon' => 'users', 'route' => 'donors.index'],
                ],
            ],
        ],
        'hospital' => [
            'label' => 'Hospital Partner',
            'badge' => 'info',
            'sections' => [
                'Hospital Operations' => [
                    ['label' => 'Requester Overview', 'icon' => 'squares-2x2', 'route' => 'dashboard', 'match' => 'dashboard'],
                    ['label' => 'Request Blood Units', 'icon' => 'plus', 'route' => 'requests.create', 'match' => 'requests.create'],
                    ['label' => 'Track Requests', 'icon' => 'clipboard-list', 'route' => 'requests.index', 'match' => ['requests.index', 'requests.show', 'requests.edit']],
                    ['label' => 'Regional Stock', 'icon' => 'droplet', 'route' => 'inventory.index'],
                ],
            ],
        ],
        'donor' => [
            'label' => 'Donor Portal',
            'badge' => 'crimson',
            'sections' => [
                'Donor Services' => [
                    ['label' => 'Overview', 'icon' => 'squares-2x2', 'route' => 'donor-portal.dashboard', 'match' => 'donor-portal.dashboard'],
                    ['label' => 'My Appointments', 'icon' => 'calendar', 'route' => 'donor-portal.appointments.index', 'match' => 'donor-portal.appointments.*'],
                    ['label' => 'Donation History', 'icon' => 'heart-pulse', 'route' => 'donor-portal.history', 'match' => 'donor-portal.history'],
                    ['label' => 'Eligibility', 'icon' => 'shield-check', 'route' => 'donor-portal.eligibility', 'match' => 'donor-portal.eligibility'],
                ],
            ],
        ],
        default => [
            'label' => 'Authenticated User',
            'badge' => 'neutral',
            'sections' => [
                'Portal' => [
                    ['label' => 'Dashboard', 'icon' => 'squares-2x2', 'route' => 'dashboard', 'match' => 'dashboard'],
                ],
            ],
        ],
    };
@endphp

<div class="flex h-full flex-col bg-white dark:bg-slate-900">
    <div class="flex h-16 items-center justify-between border-b border-slate-200/80 px-5 dark:border-slate-800">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5">
            <span class="flex size-9 items-center justify-center rounded-xl bg-crimson-600 text-white shadow-xs">
                <x-icons.icon name="droplet" class="size-5" />
            </span>
            <div class="leading-tight">
                <span class="block text-sm font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ config('app.name', 'BDMS') }}
                </span>
                <span class="block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                    {{ $roleMeta['label'] }}
                </span>
            </div>
        </a>

        <button
            type="button"
            @click="sidebarOpen = false"
            class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden dark:text-slate-400 dark:hover:bg-slate-800 cursor-pointer"
            aria-label="Close sidebar"
        >
            <x-icons.icon name="x-mark" class="size-5" />
        </button>
    </div>

    <div class="flex-1 overflow-y-auto px-3 py-4 space-y-6">
        <div class="px-2">
            <x-ui.badge :variant="$roleMeta['badge']" :dot="true" class="w-full justify-between py-1">
                <span>Active Portal</span>
                <span class="font-semibold">{{ $roleMeta['label'] }}</span>
            </x-ui.badge>
        </div>

        @foreach ($roleMeta['sections'] as $sectionTitle => $items)
            <div class="space-y-1">
                <p class="px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                    {{ $sectionTitle }}
                </p>

                <nav class="space-y-1" aria-label="{{ $sectionTitle }}">
                    @foreach ($items as $item)
                        @php
                            $routeExists = Route::has($item['route']);
                            $href = $routeExists ? route($item['route'], $item['params'] ?? []) : '#';
                            $routePrefix = \Illuminate\Support\Str::before($item['route'], '.');
                            $matchPattern = $item['match'] ?? null;
                            $isActive = $routeExists && (
                                $matchPattern
                                    ? request()->routeIs($matchPattern)
                                    : (request()->routeIs($item['route']) || ($routePrefix !== 'dashboard' && request()->routeIs($routePrefix . '.*')))
                            );
                        @endphp

                        <a
                            href="{{ $href }}"
                            @if (! $routeExists)
                                title="Module scheduled for next step"
                            @endif
                            @class([
                                'group flex items-center justify-between gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition',
                                'bg-crimson-50 text-crimson-700 dark:bg-crimson-950/60 dark:text-crimson-300' => $isActive,
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800/80 dark:hover:text-white' => ! $isActive && $routeExists,
                                'text-slate-400 dark:text-slate-500 cursor-not-allowed opacity-80' => ! $routeExists,
                            ])
                        >
                            <span class="flex items-center gap-2.5 truncate">
                                <x-icons.icon :name="$item['icon']" class="size-4.5" />
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>

                            @if (! $routeExists)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    Soon
                                </span>
                            @endif
                        </a>
                    @endforeach
                </nav>
            </div>
        @endforeach
    </div>

    <div class="border-t border-slate-200/80 p-3 space-y-1.5 dark:border-slate-800">
        <a
            href="{{ route('home') }}"
            class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white transition"
        >
            <x-icons.icon name="droplet" class="size-4.5 text-crimson-600 dark:text-crimson-400" />
            <span>Public Portal</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white transition cursor-pointer"
            >
                <x-icons.icon name="arrow-right-on-rectangle" class="size-4.5" />
                <span>Sign out</span>
            </button>
        </form>
    </div>
</div>

