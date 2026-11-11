<x-app-layout title="Donations Log" page-title="Donations Log" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Donations Log
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Track blood collection records, volume yields, laboratory screening outcomes, and collection statuses.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('donations.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Add Donation</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="donationsDirectory({ donations: @js($donations) })">
        {{-- Overview Statistic Cards --}}
        <section aria-label="Donations overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                label="Total Donations"
                :value="$summary['totalDonations']"
                trend="Logged collection & screening records"
                trend-tone="neutral"
                icon="heart-pulse"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Donations This Month"
                :value="$summary['donationsThisMonth']"
                trend="November 2026 donation cycle"
                trend-tone="up"
                icon="calendar"
                accent="sky"
            />

            <x-bdms.stat-card
                label="Total Units Collected"
                :value="$summary['totalUnitsCollected']"
                :trend="number_format($summary['totalVolumeMl']) . ' ml verified completed volume'"
                trend-tone="up"
                icon="droplet"
                accent="emerald"
            />

            <x-bdms.stat-card
                label="Pending Screening"
                :value="$summary['pendingScreening']"
                trend="Awaiting explicit lab verification"
                trend-tone="neutral"
                icon="shield-check"
                accent="amber"
            />
        </section>

        {{-- Explicit Clinical Screening Notice --}}
        <x-ui.alert variant="info" title="Explicit Laboratory Screening Verification">
            Screening statuses (<strong>Pending</strong>, <strong>Passed</strong>, and <strong>Failed</strong>) reflect explicit laboratory verification decisions. Donations are never automatically marked as medically approved.
        </x-ui.alert>

        {{-- Donations Log Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Collection &amp; Screening Ledger
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($donations) }}</span> of {{ count($donations) }} donation records
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                x-show="hasActiveFilters"
                                x-cloak
                                @click="resetFilters()"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                            >
                                <x-icons.icon name="x-mark" class="size-3.5" />
                                <span>Clear all filters</span>
                            </button>

                            <label for="donations-per-page" class="sr-only">Rows per page</label>
                            <select
                                id="donations-per-page"
                                x-model.number="perPage"
                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            >
                                <option value="5">5 / page</option>
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                            </select>
                        </div>
                    </div>

                    {{-- Search & Filter Controls --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <div class="xl:col-span-2">
                            <label for="donation-search" class="sr-only">Search by donation ID or donor name</label>
                            <x-ui.input
                                id="donation-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search Donation ID or donor name..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="filter-donation-blood-group" class="sr-only">Filter by blood group</label>
                            <select
                                id="filter-donation-blood-group"
                                x-model="bloodGroupFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Blood Groups</option>
                                @foreach ($bloodGroups as $group)
                                    <option value="{{ $group }}">{{ $group }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="filter-screening-status" class="sr-only">Filter by screening status</label>
                            <select
                                id="filter-screening-status"
                                x-model="screeningFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Screening</option>
                                <option value="Pending">Pending</option>
                                <option value="Passed">Passed</option>
                                <option value="Failed">Failed</option>
                            </select>
                        </div>

                        <div>
                            <label for="filter-collection-status" class="sr-only">Filter by collection status</label>
                            <select
                                id="filter-collection-status"
                                x-model="collectionFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Collection</option>
                                <option value="Scheduled">Scheduled</option>
                                <option value="Completed">Completed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="filter-date-from" class="sr-only">From donation date</label>
                                <input
                                    id="filter-date-from"
                                    type="date"
                                    x-model="dateFrom"
                                    title="From donation date"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                            <div>
                                <label for="filter-date-to" class="sr-only">To donation date</label>
                                <input
                                    id="filter-date-to"
                                    type="date"
                                    x-model="dateTo"
                                    title="To donation date"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donation ID</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donor Name</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Blood Group</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donation Date</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Quantity (ml)</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donation Type</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Screening Status</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Collection Status</th>
                            <th scope="col" class="px-6 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @foreach ($donations as $index => $donation)
                            @php
                                $screeningVariant = match ($donation['screeningStatus']) {
                                    'Passed' => 'success',
                                    'Failed' => 'danger',
                                    default => 'warning',
                                };

                                $collectionVariant = match ($donation['collectionStatus']) {
                                    'Completed' => 'success',
                                    'Scheduled' => 'info',
                                    default => 'danger',
                                };
                            @endphp

                            <tr
                                x-show="isRowVisible({{ $index }})"
                                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                            >
                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200">
                                    <a
                                        href="{{ route('donations.show', $donation['id']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $donation['id'] }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <a
                                            href="{{ route('donors.show', $donation['donorId']) }}"
                                            class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                        >
                                            {{ $donation['donorName'] }}
                                        </a>
                                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400">
                                            {{ $donation['donorId'] }}
                                        </p>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="sm" />
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                    {{ $donation['donationDateFormatted'] }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap font-medium text-slate-800 dark:text-slate-200">
                                    {{ $donation['quantityMl'] }} ml
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ $donation['donationType'] }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$screeningVariant" :dot="true">
                                        {{ $donation['screeningStatus'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                                        {{ $donation['collectionStatus'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <x-ui.button variant="ghost" size="sm" :href="route('donations.show', $donation['id'])">
                                            <x-icons.icon name="eye" class="size-4" />
                                            <span>View</span>
                                        </x-ui.button>

                                        <x-ui.button variant="outline" size="sm" :href="route('donations.edit', $donation['id'])">
                                            <x-icons.icon name="pencil" class="size-3.5" />
                                            <span>Edit</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Empty State --}}
            <div
                x-show="totalFiltered === 0"
                x-cloak
                class="flex flex-col items-center justify-center px-6 py-14 text-center"
            >
                <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-icons.icon name="heart-pulse" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No matching donation records found
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    Adjust your search query, status filters, or date range to locate matching blood donation records.
                </p>
                <button
                    type="button"
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-crimson-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700 cursor-pointer"
                >
                    <x-icons.icon name="x-mark" class="size-4" />
                    <span>Clear all filters</span>
                </button>
            </div>

            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingFrom"></span>
                        to <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingTo"></span>
                        of <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered"></span> donations
                    </p>

                    <nav aria-label="Donations pagination" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            @click="prevPage()"
                            :disabled="safePage <= 1"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            <x-icons.icon name="chevron-left" class="size-3.5" />
                            <span>Prev</span>
                        </button>

                        <template x-for="page in pageNumbers" :key="page">
                            <button
                                type="button"
                                @click="setPage(page)"
                                x-text="page"
                                :class="page === safePage
                                    ? 'bg-crimson-600 text-white border-crimson-600 shadow-xs'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                                class="inline-flex size-8 items-center justify-center rounded-lg border text-xs font-semibold transition cursor-pointer"
                            ></button>
                        </template>

                        <button
                            type="button"
                            @click="nextPage()"
                            :disabled="safePage >= totalPages"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            <span>Next</span>
                            <x-icons.icon name="chevron-right" class="size-3.5" />
                        </button>
                    </nav>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-app-layout>

