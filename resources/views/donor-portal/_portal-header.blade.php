@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $portalTabs = [
        [
            'label' => 'Overview',
            'icon' => 'squares-2x2',
            'href' => route('donor-portal.dashboard', $donorQuery),
            'active' => request()->routeIs('donor-portal.dashboard'),
        ],
        [
            'label' => 'My Appointments',
            'icon' => 'calendar',
            'href' => route('donor-portal.appointments.index', $donorQuery),
            'active' => request()->routeIs('donor-portal.appointments.*'),
        ],
        [
            'label' => 'Donation History',
            'icon' => 'heart-pulse',
            'href' => route('donor-portal.history', $donorQuery),
            'active' => request()->routeIs('donor-portal.history'),
        ],
        [
            'label' => 'Eligibility',
            'icon' => 'shield-check',
            'href' => route('donor-portal.eligibility', $donorQuery),
            'active' => request()->routeIs('donor-portal.eligibility'),
        ],
    ];

    $accountStatusVariant = ($donorProfile['accountStatus'] ?? 'Active') === 'Active' ? 'success' : 'neutral';
@endphp

<div class="mb-6 space-y-4">
    {{-- Distinguished Donor Portal Navigation & Identity Strip --}}
    <div class="rounded-2xl border border-crimson-200/80 bg-gradient-to-r from-crimson-50/90 via-white to-white p-4 shadow-2xs dark:border-crimson-900/60 dark:from-crimson-950/35 dark:via-slate-900 dark:to-slate-900 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-crimson-600 text-sm font-bold text-white shadow-xs">
                    {{ strtoupper(substr($donorProfile['name'], 0, 2)) }}
                </div>

                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-base font-bold text-slate-900 dark:text-white">
                            {{ $donorProfile['name'] }}
                        </span>
                        <span class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ $donorProfile['id'] }}
                        </span>
                        <x-bdms.blood-group-badge :group="$donorProfile['bloodGroup']" size="sm" />
                        <x-ui.badge :variant="$accountStatusVariant" :dot="true">
                            {{ $donorProfile['accountStatus'] }}
                        </x-ui.badge>
                    </div>

                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600 dark:text-slate-400">
                        <span>Contact: <strong class="font-medium text-slate-800 dark:text-slate-200">{{ $donorProfile['maskedEmail'] }}</strong> &middot; <strong class="font-medium text-slate-800 dark:text-slate-200">{{ $donorProfile['maskedPhone'] }}</strong></span>
                        <span>Registered: <strong class="font-medium text-slate-800 dark:text-slate-200">{{ $donorProfile['registrationDate'] }}</strong></span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button variant="primary" size="sm" :href="route('donor-portal.appointments.create', $donorQuery)">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Book Appointment</span>
                </x-ui.button>
            </div>
        </div>

        <nav aria-label="Donor portal navigation" class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-slate-200/80 pt-3 dark:border-slate-800">
            @foreach ($portalTabs as $tab)
                <a
                    href="{{ $tab['href'] }}"
                    @class([
                        'inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-semibold transition',
                        'bg-crimson-600 text-white shadow-xs' => $tab['active'],
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' => ! $tab['active'],
                    ])
                >
                    <x-icons.icon :name="$tab['icon']" class="size-4" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
</div>

