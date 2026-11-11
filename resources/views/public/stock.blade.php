<x-guest-layout title="Blood Stock Availability">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        {{-- Page Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge variant="crimson" :dot="true">Public Inventory Lookup</x-ui.badge>
                    <x-ui.badge variant="neutral">{{ $summary['totalGroups'] }} Standard ABO/Rh Groups</x-ui.badge>
                </div>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Public Blood Stock Availability
                </h1>
                <p class="max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                    Inspect current available units and readiness status across every blood group to see where community donations are most urgently needed.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <x-ui.button :href="route('public.camps.index')" variant="outline" size="sm">
                    <x-icons.icon name="calendar" class="size-4" />
                    Find Donation Camps
                </x-ui.button>
                <x-ui.button :href="route('donor-portal.appointments.create')" variant="primary" size="sm">
                    <x-icons.icon name="heart-pulse" class="size-4" />
                    Book in Donor Portal
                </x-ui.button>
            </div>
        </div>

        {{-- Explicit Preview / Sample Data Notice --}}
        <x-ui.alert variant="info" title="Preview Sample Stock Data" :dismissible="false">
            Availability counts and stock status thresholds are synchronized directly from BDMS sample inventory figures as of {{ $todayIso }}. Reserved hospital allocations, donor records, and internal audit logs are excluded from public view.
        </x-ui.alert>

        {{-- Summary Stat Cards --}}
        <section aria-label="Public stock summary" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-bdms.stat-card
                label="Available Sample Units"
                :value="$summary['totalUnits']"
                trend="Across all 8 ABO/Rh blood types"
                trend-tone="up"
                icon="droplet"
                accent="crimson"
            />
            <x-bdms.stat-card
                label="Healthy Supply Groups"
                :value="$summary['normalCount']"
                trend="Above minimum reserve target"
                trend-tone="up"
                icon="check-circle"
                accent="emerald"
            />
            <x-bdms.stat-card
                label="Low Stock Groups"
                :value="$summary['lowCount']"
                trend="At or below minimum threshold"
                trend-tone="neutral"
                icon="exclamation-triangle"
                accent="amber"
            />
            <x-bdms.stat-card
                label="Critical Priority Groups"
                :value="$summary['criticalCount']"
                :trend="'Immediate outreach: ' . (implode(', ', $summary['urgentGroups']) ?: 'None')"
                trend-tone="down"
                icon="activity"
                accent="crimson"
            />
        </section>

        {{-- Interactive Filter & Blood Group Grid --}}
        <section
            x-data="publicBloodStock(@js(['groups' => $bloodGroups]))"
            aria-labelledby="public-stock-grid-heading"
            class="space-y-6"
        >
            <x-ui.card>
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 id="public-stock-grid-heading" class="text-base font-semibold text-slate-900 dark:text-white">
                            Filter Blood Group Availability
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="filteredCount">{{ count($bloodGroups) }}</span> of {{ count($bloodGroups) }} monitored blood groups.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <select
                            x-model="groupFilter"
                            aria-label="Filter by blood group"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-2xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        >
                            <option value="all">All Blood Groups</option>
                            @foreach ($bloodGroups as $item)
                                <option value="{{ $item['group'] }}">{{ $item['group'] }}</option>
                            @endforeach
                        </select>

                        <select
                            x-model="statusFilter"
                            aria-label="Filter by stock status"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-2xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        >
                            <option value="all">All Stock Statuses</option>
                            <option value="critical">Critical</option>
                            <option value="low">Low Stock</option>
                            <option value="normal">Normal</option>
                        </select>

                        <button
                            type="button"
                            x-show="hasActiveFilters"
                            x-cloak
                            @click="resetFilters()"
                            class="rounded-lg px-3 py-2 text-xs font-medium text-crimson-600 hover:bg-crimson-50 dark:text-crimson-400 dark:hover:bg-crimson-950/50 cursor-pointer transition"
                        >
                            Reset Filters
                        </button>
                    </div>
                </div>
            </x-ui.card>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($bloodGroups as $index => $item)
                    @php
                        $badgeVariant = match ($item['status']) {
                            'critical' => 'danger',
                            'low' => 'warning',
                            default => 'success',
                        };
                    @endphp

                    <x-ui.card
                        x-show="isGroupVisible({{ $index }})"
                        class="flex flex-col justify-between gap-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <x-bdms.blood-group-badge :group="$item['group']" size="lg" />
                            <x-ui.badge :variant="$badgeVariant" :dot="true">
                                {{ $item['statusLabel'] }}
                            </x-ui.badge>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-2xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-white">
                                    {{ $item['available'] }}
                                    <span class="text-xs font-normal text-slate-500 dark:text-slate-400">units available</span>
                                </p>
                                <span class="text-xs font-medium tabular-nums text-slate-500 dark:text-slate-400">
                                    Min {{ $item['minLevel'] }}
                                </span>
                            </div>

                            <x-bdms.stock-level-bar
                                :group="$item['group']"
                                :units="$item['available']"
                                :capacity="$item['capacity']"
                                :status="$item['status']"
                            />
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400">
                                Updated {{ $item['lastUpdated'] }}
                            </span>
                            <a
                                href="{{ route('public.camps.index') }}"
                                class="font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400"
                            >
                                {{ $item['status'] === 'normal' ? 'View Camps' : 'Donate Now' }} &rarr;
                            </a>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            <x-ui.card x-show="filteredCount === 0" x-cloak class="text-center py-10">
                <div class="mx-auto max-w-md space-y-3">
                    <x-icons.icon name="magnifying-glass" class="mx-auto size-8 text-slate-400" />
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                        No blood groups match these filters
                    </h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        Try clearing your blood group or status selection to inspect all 8 ABO/Rh stock indicators.
                    </p>
                    <div>
                        <x-ui.button variant="outline" size="sm" @click="resetFilters()">
                            Reset Filters
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>
        </section>
    </div>
</x-guest-layout>

