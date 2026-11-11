@php
    $historyPayload = [
        'donations' => $donations,
    ];
@endphp

<x-app-layout title="Donation History — Donor Portal" page-title="Donation History" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    My Donation History
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Read-only archive of your blood donations, collection statuses, and verified screening progress.
                </p>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="space-y-6" x-data="donationHistory(@js($historyPayload))">
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Verified Donation &amp; Screening Log
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="filteredDonations.length">{{ count($donations) }}</span> of {{ count($donations) }} recorded donations (read-only)
                            </p>
                        </div>

                        <button
                            type="button"
                            x-show="hasActiveFilters"
                            x-cloak
                            @click="resetFilters()"
                            class="inline-flex items-center gap-1.5 self-start rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            <x-icons.icon name="x-mark" class="size-3.5" />
                            <span>Clear all filters</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div class="xl:col-span-2">
                            <label for="history-search" class="sr-only">Search by Donation ID</label>
                            <x-ui.input
                                id="history-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search by Donation ID (e.g. DON-4405)..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="history-status-filter" class="sr-only">Filter by donation status</label>
                            <select
                                id="history-status-filter"
                                x-model="statusFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Donation Statuses</option>
                                @foreach ($donationStatuses as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="history-date-from" class="sr-only">From donation date</label>
                            <input
                                id="history-date-from"
                                type="date"
                                x-model="dateFrom"
                                title="From date"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>

                        <div>
                            <label for="history-date-to" class="sr-only">To donation date</label>
                            <input
                                id="history-date-to"
                                type="date"
                                x-model="dateTo"
                                title="To date"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Donation ID</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Donation Date</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Donation Type</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Blood Group</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Collection Location</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Donation Status</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Screening Status</th>
                            <th scope="col" class="px-5 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        <template x-for="item in filteredDonations" :key="item.id">
                            <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                <td class="px-5 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200" x-text="item.id"></td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300" x-text="item.donationDateFormatted"></td>

                                <td class="px-5 py-4 whitespace-nowrap font-medium text-slate-900 dark:text-white">
                                    <span x-text="item.donationType"></span>
                                    <span class="block text-xs text-slate-400" x-text="`${item.quantityMl} ml`"></span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-lg bg-crimson-50 px-2.5 py-0.5 text-xs font-bold text-crimson-700 ring-1 ring-inset ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800/70"
                                        x-text="item.bloodGroup"
                                    ></span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300" x-text="item.collectionLocation"></td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                        :class="statusBadgeClasses(item.collectionStatus)"
                                        x-text="item.collectionStatus"
                                    ></span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                        :class="statusBadgeClasses(item.screeningStatus)"
                                        x-text="item.screeningStatus"
                                    ></span>
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <button
                                        type="button"
                                        @click="viewDonation(item)"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                                    >
                                        <x-icons.icon name="eye" class="size-3.5" />
                                        <span>View Details</span>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <noscript>
                    <div class="divide-y divide-slate-200 px-5 py-3 text-sm">
                        @foreach ($donations as $item)
                            <div class="py-2">
                                <strong>{{ $item['id'] }}</strong> &middot; {{ $item['donationDateFormatted'] }} &middot; {{ $item['donationType'] }} &middot; {{ $item['bloodGroup'] }} &middot; {{ $item['collectionLocation'] }} ({{ $item['collectionStatus'] }} / Screening: {{ $item['screeningStatus'] }})
                            </div>
                        @endforeach
                    </div>
                </noscript>
            </div>

            {{-- Empty State --}}
            <div
                x-show="filteredDonations.length === 0"
                x-cloak
                class="flex flex-col items-center justify-center px-6 py-14 text-center"
            >
                <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-icons.icon name="heart-pulse" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No matching donation history found
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    Adjust your Donation ID search, date range, or donation status filter to view past records.
                </p>
                <button
                    type="button"
                    x-show="hasActiveFilters"
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-crimson-600 px-4 py-2 text-xs font-semibold text-white hover:bg-crimson-700 cursor-pointer"
                >
                    <span>Clear all filters</span>
                </button>
            </div>
        </x-ui.card>

        {{-- Read-only Donation Details Modal (Zero Staff-Only Notes Exposed) --}}
        <x-ui.modal name="donor-donation-detail-modal" title="Donation &amp; Screening Summary" max-width="lg">
            <div x-show="selectedDonation !== null" class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-slate-900 dark:text-white" x-text="selectedDonation?.id"></span>
                        <span class="rounded-md bg-crimson-50 px-2 py-0.5 text-xs font-bold text-crimson-700 dark:bg-crimson-950/60 dark:text-crimson-300" x-text="selectedDonation?.bloodGroup"></span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="statusBadgeClasses(selectedDonation?.collectionStatus)"
                            x-text="`Collection: ${selectedDonation?.collectionStatus}`"
                        ></span>
                        <span
                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="statusBadgeClasses(selectedDonation?.screeningStatus)"
                            x-text="`Screening: ${selectedDonation?.screeningStatus}`"
                        ></span>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-xs dark:border-slate-800 dark:bg-slate-800/40 sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Donation Date &amp; Time</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="selectedDonation?.donationDateTime"></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Collection Location</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="selectedDonation?.collectionLocation"></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Donation Type &amp; Volume</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="`${selectedDonation?.donationType} (${selectedDonation?.quantityMl} ml)`"></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Blood Group</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="selectedDonation?.bloodGroup"></dd>
                    </div>
                </dl>

                {{-- Related Screening Records --}}
                <div class="space-y-2">
                    <h4 class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                        Related Screening Records
                    </h4>

                    <template x-if="(selectedDonation?.relatedScreenings ?? []).length > 0">
                        <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                            <template x-for="scr in selectedDonation?.relatedScreenings" :key="scr.id">
                                <div class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-xs">
                                    <div>
                                        <span class="font-mono font-semibold text-slate-700 dark:text-slate-200" x-text="scr.id"></span>
                                        <span class="mx-1 text-slate-400">&middot;</span>
                                        <span class="font-medium text-slate-900 dark:text-white" x-text="scr.testType"></span>
                                        <span class="block text-[11px] text-slate-500 dark:text-slate-400" x-text="`Test date: ${scr.testDateFormatted}`"></span>
                                    </div>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset"
                                        :class="statusBadgeClasses(scr.screeningResult)"
                                        x-text="scr.screeningResult"
                                    ></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="(selectedDonation?.relatedScreenings ?? []).length === 0">
                        <p class="rounded-xl border border-dashed border-slate-200 px-3.5 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            No individual screening panel details attached to this archived summary.
                        </p>
                    </template>
                </div>

                <div class="rounded-xl border border-slate-200 p-3.5 text-xs dark:border-slate-800">
                    <p class="font-semibold text-slate-800 dark:text-slate-200">Additional Notes</p>
                    <p class="mt-1 text-slate-600 dark:text-slate-300" x-text="selectedDonation?.donorNote"></p>
                    <p class="mt-2 text-[11px] text-slate-400">
                        Historical donation and laboratory screening records are read-only and managed by authorized blood bank personnel.
                    </p>
                </div>

                <div class="flex justify-end pt-2">
                    <button
                        type="button"
                        @click="$dispatch('close-modal', 'donor-donation-detail-modal')"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 cursor-pointer"
                    >
                        Close
                    </button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>

