<x-app-layout title="Lab Screening" page-title="Lab Screening" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Lab Screening
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Track infectious disease assays, blood group compatibility panels, and explicit laboratory test outcomes.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('screening.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Record Screening Test</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="screeningDirectory({ samples: @js($samples) })">
        {{-- Overview Statistic Cards --}}
        <section aria-label="Lab screening overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                label="Total Samples"
                :value="$summary['totalSamples']"
                trend="Accessioned laboratory specimens"
                trend-tone="neutral"
                icon="clipboard-list"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Pending Tests"
                :value="$summary['pendingTests']"
                trend="Awaiting explicit laboratory verification"
                trend-tone="neutral"
                icon="clock"
                accent="amber"
            />

            <x-bdms.stat-card
                label="Passed Samples"
                :value="$summary['passedSamples']"
                trend="Explicitly marked Passed in sample log"
                trend-tone="up"
                icon="check-circle"
                accent="emerald"
            />

            <x-bdms.stat-card
                label="Failed Samples"
                :value="$summary['failedSamples']"
                trend="Flagged for quarantine & medical review"
                trend-tone="down"
                icon="exclamation-triangle"
                accent="sky"
            />
        </section>

        {{-- Clinical Rule Disclaimer --}}
        <x-ui.alert variant="info" title="Laboratory Screening Policy (Preview Data Only)">
            Screening results (<strong>Pending</strong>, <strong>Passed</strong>, and <strong>Failed</strong>) must be selected explicitly per sample and are never marked Passed automatically. Sample records shown here are frontend preview fixtures and do not represent real clinical clearance.
        </x-ui.alert>

        {{-- Screening Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Laboratory Sample Queue
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($samples) }}</span> of {{ count($samples) }} screening samples
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

                            <label for="screening-per-page" class="sr-only">Rows per page</label>
                            <select
                                id="screening-per-page"
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
                            <label for="screening-search" class="sr-only">Search screening ID, donation ID, or donor name</label>
                            <x-ui.input
                                id="screening-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search Screening ID, Donation ID, or donor..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="filter-screening-blood-group" class="sr-only">Filter by blood group</label>
                            <select
                                id="filter-screening-blood-group"
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
                            <label for="filter-screening-result" class="sr-only">Filter by screening result</label>
                            <select
                                id="filter-screening-result"
                                x-model="resultFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Results</option>
                                <option value="Pending">Pending</option>
                                <option value="Passed">Passed</option>
                                <option value="Failed">Failed</option>
                            </select>
                        </div>

                        <div>
                            <label for="filter-test-type" class="sr-only">Filter by test type</label>
                            <select
                                id="filter-test-type"
                                x-model="testTypeFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Test Types</option>
                                @foreach ($testTypes as $testType)
                                    <option value="{{ $testType }}">{{ $testType }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="screening-date-from" class="sr-only">From date</label>
                                <input
                                    id="screening-date-from"
                                    type="date"
                                    x-model="dateFrom"
                                    title="From date"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                            <div>
                                <label for="screening-date-to" class="sr-only">To date</label>
                                <input
                                    id="screening-date-to"
                                    type="date"
                                    x-model="dateTo"
                                    title="To date"
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
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Screening ID</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donation ID</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donor Name</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Blood Group</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Sample Collection Date</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Test Date</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Test Type</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Screening Result</th>
                            <th scope="col" class="px-6 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @foreach ($samples as $index => $sample)
                            @php
                                $resultVariant = match ($sample['screeningResult']) {
                                    'Passed' => 'success',
                                    'Failed' => 'danger',
                                    default => 'warning',
                                };
                            @endphp

                            <tr
                                x-show="isRowVisible({{ $index }})"
                                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                            >
                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200">
                                    <a
                                        href="{{ route('screening.show', $sample['id']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $sample['id'] }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-700 dark:text-slate-300">
                                    <a
                                        href="{{ route('donations.show', $sample['donationId']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $sample['donationId'] }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <a
                                            href="{{ route('donors.show', $sample['donorId']) }}"
                                            class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                        >
                                            {{ $sample['donorName'] }}
                                        </a>
                                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400">
                                            {{ $sample['donorId'] }}
                                        </p>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="sm" />
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ $sample['collectionDateFormatted'] }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                    @if ($sample['testDate'])
                                        <span>{{ $sample['testDateFormatted'] }}</span>
                                    @else
                                        <span class="text-xs text-slate-400 italic dark:text-slate-500">{{ $sample['testDateFormatted'] }}</span>
                                    @endif
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap font-medium text-slate-700 dark:text-slate-200">
                                    {{ $sample['testType'] }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$resultVariant" :dot="true">
                                        {{ $sample['screeningResult'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <x-ui.button variant="ghost" size="sm" :href="route('screening.show', $sample['id'])">
                                        <x-icons.icon name="eye" class="size-4" />
                                        <span>View</span>
                                    </x-ui.button>
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
                    <x-icons.icon name="shield-check" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No matching lab screening samples found
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    Try adjusting your search keywords, test panel selection, result status, or date range filter.
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
                        of <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered"></span> samples
                    </p>

                    <nav aria-label="Screening pagination" class="flex items-center gap-1.5">
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

