<x-app-layout page-title="Blood Inventory" :active-role="$activeRole">
    <x-slot:header>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Blood Inventory Management
                </h2>
                <x-ui.badge variant="crimson">8 Blood Groups</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                Monitor available units, reserve thresholds, and recent blood stock adjustments across the central bank.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="outline" size="sm" :href="route('dashboard')">
                Back to Dashboard
            </x-ui.button>
            @if (auth()->user()?->hasPermission('inventory.adjust'))
                <x-ui.button
                    variant="primary"
                    size="sm"
                    x-data
                    @click="$dispatch('inventory-open-adjust', { group: '' })"
                >
                    <x-icons.icon name="adjustments" class="size-4" />
                    Adjust Inventory
                </x-ui.button>
            @endif
        </div>
    </x-slot:header>

    <div
        x-data="inventoryManager(@js([
            'groups' => $bloodGroups,
            'history' => $history,
            'actor' => auth()->user()?->name ?? 'Authenticated User',
        ]))"
        x-on:inventory-open-adjust.window="openAdjustModal($event.detail?.group || '')"
        class="space-y-6"
    >
        {{-- Live feedback alert after client-side stock adjustment --}}
        <div
            x-show="feedbackMessage !== ''"
            x-cloak
            x-transition.opacity.duration.200ms
            role="status"
            class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800/70 dark:bg-emerald-950/60 dark:text-emerald-200"
        >
            <x-icons.icon name="check-circle" class="size-5 mt-0.5 shrink-0" />
            <div class="flex-1 min-w-0">
                <p class="font-semibold">Inventory updated</p>
                <p class="mt-0.5 opacity-90" x-text="feedbackMessage"></p>
            </div>
            <button
                type="button"
                @click="feedbackMessage = ''"
                class="rounded-md p-1 opacity-70 hover:opacity-100 transition cursor-pointer"
                aria-label="Dismiss notification"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        {{-- 2. Inventory Statistics --}}
        <section aria-label="Inventory summary statistics" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                label="Total Available Units"
                :value="$summary['totalUnits']"
                value-bind="totalUnits"
                trend="Ready for crossmatch & dispatch"
                trend-tone="up"
                icon="droplet"
                accent="crimson"
            />
            <x-bdms.stat-card
                label="Total Blood Groups"
                :value="$summary['totalGroups']"
                value-bind="groups.length"
                trend="All standard ABO & Rh(D) groups"
                trend-tone="neutral"
                icon="archive-box"
                accent="sky"
            />
            <x-bdms.stat-card
                label="Low Stock Groups"
                :value="$summary['lowStockGroups']"
                value-bind="lowStockCount"
                trend="At or below minimum threshold"
                trend-tone="neutral"
                icon="exclamation-triangle"
                accent="amber"
            />
            <x-bdms.stat-card
                label="Critical Stock Groups"
                :value="$summary['criticalStockGroups']"
                value-bind="criticalStockCount"
                trend="Immediate donor outreach required"
                trend-tone="down"
                icon="activity"
                accent="crimson"
            />
        </section>

        {{-- 1. Inventory Overview (All 8 Standard Blood Groups) --}}
        <section aria-labelledby="inventory-overview-heading" class="space-y-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 id="inventory-overview-heading" class="text-base font-semibold text-slate-900 dark:text-white">
                        Blood Group Stock Overview
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Live stock status across all 8 ABO/Rh blood groups against target capacity (100 units).
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <x-ui.badge variant="success" :dot="true">Normal</x-ui.badge>
                    <x-ui.badge variant="warning" :dot="true">Low Stock</x-ui.badge>
                    <x-ui.badge variant="danger" :dot="true">Critical</x-ui.badge>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($bloodGroups as $index => $item)
                    @php
                        $initialPercent = $item['capacity'] > 0 ? min(100, (int) round(($item['available'] / $item['capacity']) * 100)) : 0;
                        $initialStatusLabel = match ($item['status']) {
                            'critical' => 'Critical',
                            'low' => 'Low Stock',
                            default => 'Normal',
                        };
                    @endphp

                    <x-ui.card class="flex flex-col justify-between gap-4">
                        <div class="flex items-start justify-between gap-3">
                            <x-bdms.blood-group-badge :group="$item['group']" size="lg" />

                            <span
                                :class="statusBadgeClasses(groups[{{ $index }}].status)"
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                            >
                                <span
                                    :class="statusDotClasses(groups[{{ $index }}].status)"
                                    class="size-1.5 rounded-full"
                                    aria-hidden="true"
                                ></span>
                                <span x-text="statusText(groups[{{ $index }}].status)">{{ $initialStatusLabel }}</span>
                            </span>
                        </div>

                        <div class="space-y-2.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-2xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-white">
                                    <span x-text="groups[{{ $index }}].available">{{ $item['available'] }}</span>
                                    <span class="text-xs font-normal text-slate-500 dark:text-slate-400">units</span>
                                </p>
                                <span class="text-xs font-medium tabular-nums text-slate-500 dark:text-slate-400">
                                    Min {{ $item['minLevel'] }}
                                </span>
                            </div>

                            <div
                                class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                                role="progressbar"
                                :aria-valuenow="stockPercent(groups[{{ $index }}])"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="{{ $item['group'] }} stock level"
                            >
                                <div
                                    :class="barColorClasses(groups[{{ $index }}].status)"
                                    class="h-full rounded-full transition-all duration-300"
                                    style="width: {{ $initialPercent }}%"
                                    :style="`width: ${stockPercent(groups[{{ $index }}])}%`"
                                ></div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 tabular-nums">
                                Reserved:
                                <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="groups[{{ $index }}].reserved">{{ $item['reserved'] }}</span>
                            </span>

                            <button
                                type="button"
                                @click="openAdjustModal('{{ $item['group'] }}')"
                                class="inline-flex items-center gap-1 font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400 dark:hover:text-crimson-300 cursor-pointer"
                            >
                                <span>Adjust</span>
                                <x-icons.icon name="chevron-right" class="size-3.5" />
                            </button>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        </section>

        {{-- 3. Detailed Inventory Table with Search, Filtering & Pagination UI --}}
        <section aria-labelledby="inventory-table-heading">
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 id="inventory-table-heading" class="text-base font-semibold text-slate-900 dark:text-white">
                                    Detailed Blood Inventory
                                </h3>
                                <x-ui.badge variant="neutral">
                                    <span x-text="filteredIndices.length + ' of ' + groups.length">8 of 8</span>
                                </x-ui.badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Filter by blood group or stock threshold and adjust available or reserved units.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5">
                            <div class="relative w-full sm:w-56">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                                    <x-icons.icon name="magnifying-glass" class="size-4" />
                                </div>
                                <input
                                    type="search"
                                    x-model="search"
                                    placeholder="Search group or status..."
                                    aria-label="Search blood inventory"
                                    class="block w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-xs text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                                />
                            </div>

                            <select
                                x-model="statusFilter"
                                aria-label="Filter by stock status"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-2xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                            >
                                <option value="all">All Statuses</option>
                                <option value="normal">Normal</option>
                                <option value="low">Low Stock</option>
                                <option value="critical">Critical</option>
                            </select>

                            <select
                                x-model="groupFilter"
                                aria-label="Filter by blood group"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-2xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                            >
                                <option value="all">All Groups</option>
                                @foreach ($bloodGroups as $item)
                                    <option value="{{ $item['group'] }}">{{ $item['group'] }}</option>
                                @endforeach
                            </select>

                            <select
                                x-model.number="perPage"
                                aria-label="Rows per page"
                                class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-2xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                            >
                                <option value="4">4 / page</option>
                                <option value="8">8 / page</option>
                            </select>

                            <button
                                type="button"
                                x-show="hasActiveFilters"
                                x-cloak
                                @click="resetFilters()"
                                class="rounded-lg px-2.5 py-2 text-xs font-medium text-crimson-600 hover:bg-crimson-50 dark:text-crimson-400 dark:hover:bg-crimson-950/50 cursor-pointer transition"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </x-slot:header>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3.5">Blood Group</th>
                                <th scope="col" class="px-5 py-3.5">Available Units</th>
                                <th scope="col" class="px-5 py-3.5">Reserved Units</th>
                                <th scope="col" class="px-5 py-3.5">Minimum Stock Level</th>
                                <th scope="col" class="px-5 py-3.5">Stock Status</th>
                                <th scope="col" class="px-5 py-3.5">Last Updated</th>
                                <th scope="col" class="px-5 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            @foreach ($bloodGroups as $index => $item)
                                <tr
                                    x-show="isRowVisible({{ $index }})"
                                    class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition"
                                >
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <x-bdms.blood-group-badge :group="$item['group']" size="sm" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 font-semibold tabular-nums text-slate-900 dark:text-white">
                                        <span x-text="groups[{{ $index }}].available">{{ $item['available'] }}</span> units
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 tabular-nums text-slate-600 dark:text-slate-300">
                                        <span x-text="groups[{{ $index }}].reserved">{{ $item['reserved'] }}</span> units
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 tabular-nums text-slate-600 dark:text-slate-300">
                                        {{ $item['minLevel'] }} units
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <span
                                            :class="statusBadgeClasses(groups[{{ $index }}].status)"
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        >
                                            <span
                                                :class="statusDotClasses(groups[{{ $index }}].status)"
                                                class="size-1.5 rounded-full"
                                                aria-hidden="true"
                                            ></span>
                                            <span x-text="statusText(groups[{{ $index }}].status)">
                                                {{ $item['status'] === 'critical' ? 'Critical' : ($item['status'] === 'low' ? 'Low Stock' : 'Normal') }}
                                            </span>
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-xs text-slate-500 dark:text-slate-400" x-text="groups[{{ $index }}].lastUpdated">
                                        {{ $item['lastUpdated'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right">
                                        <x-ui.button
                                            variant="outline"
                                            size="sm"
                                            @click="openAdjustModal('{{ $item['group'] }}')"
                                        >
                                            <x-icons.icon name="adjustments" class="size-3.5" />
                                            Adjust
                                        </x-ui.button>
                                    </td>
                                </tr>
                            @endforeach

                            <tr x-show="filteredIndices.length === 0" x-cloak>
                                <td colspan="7" class="px-5 py-10 text-center">
                                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300">
                                        No blood groups match your current search or filters.
                                    </p>
                                    <div class="mt-3">
                                        <x-ui.button variant="outline" size="sm" @click="resetFilters()">
                                            Reset Filters
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <x-slot:footer>
                    <div class="flex flex-col items-center justify-between gap-3 sm:flex-row">
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Showing
                            <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="pageStart">1</span>–<span class="font-semibold text-slate-700 dark:text-slate-200" x-text="pageEnd">4</span>
                            of
                            <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="filteredIndices.length">8</span>
                            blood groups
                        </p>

                        <nav class="flex items-center gap-1.5" aria-label="Inventory pagination">
                            <button
                                type="button"
                                @click="prevPage()"
                                :disabled="currentPage <= 1"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition hover:bg-slate-50 disabled:opacity-40 disabled:pointer-events-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                            >
                                <x-icons.icon name="chevron-left" class="size-3.5" />
                                <span>Prev</span>
                            </button>

                            <template x-for="page in totalPages" :key="page">
                                <button
                                    type="button"
                                    @click="goToPage(page)"
                                    :class="currentPage === page
                                        ? 'border-crimson-600 bg-crimson-600 text-white dark:border-crimson-500 dark:bg-crimson-600'
                                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'"
                                    class="inline-flex size-8 items-center justify-center rounded-lg border text-xs font-semibold tabular-nums transition cursor-pointer"
                                    x-text="page"
                                ></button>
                            </template>

                            <button
                                type="button"
                                @click="nextPage()"
                                :disabled="currentPage >= totalPages"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs transition hover:bg-slate-50 disabled:opacity-40 disabled:pointer-events-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                            >
                                <span>Next</span>
                                <x-icons.icon name="chevron-right" class="size-3.5" />
                            </button>
                        </nav>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </section>

        {{-- 5. Inventory History --}}
        <section aria-labelledby="inventory-history-heading">
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 id="inventory-history-heading" class="text-base font-semibold text-slate-900 dark:text-white">
                                Recent Inventory History
                            </h3>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Audit log of recent blood stock additions, removals, and hospital reservations.
                            </p>
                        </div>

                        <div class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 p-0.5 dark:border-slate-700 dark:bg-slate-950" role="group" aria-label="Filter history by operation">
                            @foreach (['all' => 'All', 'Added' => 'Added', 'Removed' => 'Removed', 'Reserved' => 'Reserved'] as $value => $label)
                                <button
                                    type="button"
                                    @click="historyFilter = '{{ $value }}'"
                                    :class="historyFilter === '{{ $value }}'
                                        ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-800 dark:text-white'
                                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium transition cursor-pointer"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </x-slot:header>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3">Blood Group</th>
                                <th scope="col" class="px-5 py-3">Operation</th>
                                <th scope="col" class="px-5 py-3">Units</th>
                                <th scope="col" class="px-5 py-3">Description</th>
                                <th scope="col" class="px-5 py-3">Responsible User</th>
                                <th scope="col" class="px-5 py-3 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            <template x-for="entry in filteredHistory" :key="entry.id">
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <span
                                            :class="groupPillClasses(entry.group)"
                                            class="inline-flex min-w-10 items-center justify-center rounded-lg px-2.5 py-1 text-xs font-bold tracking-tight tabular-nums ring-1 ring-inset"
                                            x-text="entry.group"
                                        ></span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <span
                                            :class="operationBadgeClasses(entry.operation)"
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            x-text="entry.operation"
                                        ></span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 font-semibold tabular-nums text-slate-900 dark:text-white">
                                        <span x-text="entry.operation === 'Added' ? '+' + entry.units : (entry.operation === 'Removed' ? '-' + entry.units : entry.units)"></span>
                                        <span class="font-normal text-slate-500 dark:text-slate-400" x-text="entry.units === 1 ? ' unit' : ' units'"></span>
                                    </td>
                                    <td class="px-5 py-3.5 text-xs text-slate-600 dark:text-slate-300 max-w-md">
                                        <span x-text="entry.description"></span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-xs font-medium text-slate-800 dark:text-slate-200" x-text="entry.user"></td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right text-xs text-slate-500 dark:text-slate-400" x-text="entry.date"></td>
                                </tr>
                            </template>

                            <tr x-show="filteredHistory.length === 0" x-cloak>
                                <td colspan="6" class="px-5 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                                    No inventory activities recorded for this operation type.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </section>

        {{-- 6. Adjust Inventory Modal --}}
        <x-ui.modal name="adjust-inventory" title="Adjust Blood Inventory" maxWidth="lg">
            <form @submit.prevent="submitAdjustment()" novalidate class="space-y-4">
                <div class="space-y-1.5">
                    <label for="adjust-blood-group" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                        Blood group <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
                    </label>
                    <select
                        id="adjust-blood-group"
                        x-model="form.bloodGroup"
                        :class="errors.bloodGroup
                            ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                            : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400'"
                        class="block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100"
                    >
                        <option value="">Select blood group</option>
                        @foreach ($bloodGroups as $item)
                            <option value="{{ $item['group'] }}">{{ $item['group'] }}</option>
                        @endforeach
                    </select>
                    <p x-show="errors.bloodGroup" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400" x-text="errors.bloodGroup"></p>

                    <div
                        x-show="selectedGroupItem"
                        x-cloak
                        class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-950 dark:text-slate-300"
                    >
                        <span>
                            Current available:
                            <strong class="tabular-nums text-slate-900 dark:text-white" x-text="selectedGroupItem?.available + ' units'"></strong>
                        </span>
                        <span>
                            Reserved:
                            <strong class="tabular-nums text-slate-900 dark:text-white" x-text="selectedGroupItem?.reserved + ' units'"></strong>
                        </span>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <label for="adjust-operation" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Operation type <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
                        </label>
                        <select
                            id="adjust-operation"
                            x-model="form.operation"
                            :class="errors.operation
                                ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                                : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400'"
                            class="block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="Added">Added (Stock In)</option>
                            <option value="Removed">Removed (Dispatch / Expired)</option>
                            <option value="Reserved">Reserved (Hospital Hold)</option>
                        </select>
                        <p x-show="errors.operation" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400" x-text="errors.operation"></p>
                    </div>

                    <div class="space-y-1.5">
                        <label for="adjust-units" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Number of units <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
                        </label>
                        <input
                            id="adjust-units"
                            type="number"
                            min="1"
                            step="1"
                            x-model="form.units"
                            placeholder="e.g. 5"
                            :class="errors.units
                                ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                                : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400'"
                            class="block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                        />
                        <p x-show="errors.units" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400" x-text="errors.units"></p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label for="adjust-description" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                        Description / Reason <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        id="adjust-description"
                        rows="3"
                        x-model="form.description"
                        placeholder="Enter batch reference, hospital request ID, or reason for stock adjustment..."
                        :class="errors.description
                            ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                            : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400'"
                        class="block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                    ></textarea>
                    <p x-show="errors.description" x-cloak class="text-xs font-medium text-rose-600 dark:text-rose-400" x-text="errors.description"></p>
                </div>

                <div class="flex items-center justify-end gap-2.5 border-t border-slate-200 pt-4 dark:border-slate-800">
                    <x-ui.button type="button" variant="outline" @click="closeAdjustModal()">
                        Cancel
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary">
                        <x-icons.icon name="check" class="size-4" />
                        Submit Adjustment
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</x-app-layout>
