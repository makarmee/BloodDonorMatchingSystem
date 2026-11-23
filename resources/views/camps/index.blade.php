<x-app-layout :title="__('Donation Camps Management')" :page-title="__('Donation Camps')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Donation Camps Management') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Organize community blood drives, monitor slot capacity, and manage donor registrations & attendance.') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('camps.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>{{ __('Create Camp') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="campsDirectory({ camps: @js($camps) })">
        {{-- Summary Statistics Cards (6 required cards) --}}
        <section aria-label="Donation camps overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-bdms.stat-card
                :label="__('Total Camps')"
                :value="$summary['totalCamps']"
                :trend="__('Scheduled, active, draft & archived drives')"
                trend-tone="neutral"
                icon="calendar"
                accent="crimson"
            />

            <x-bdms.stat-card
                :label="__('Upcoming Camps')"
                :value="$summary['upcomingCamps']"
                :trend="__('Open or scheduled for donor booking')"
                trend-tone="neutral"
                icon="clock"
                accent="sky"
            />

            <x-bdms.stat-card
                :label="__('Ongoing Camps')"
                :value="$summary['ongoingCamps']"
                :trend="__('Currently operating on-site drives')"
                trend-tone="up"
                icon="heart-pulse"
                accent="emerald"
            />

            <x-bdms.stat-card
                :label="__('Completed Camps')"
                :value="$summary['completedCamps']"
                :trend="__('Closed drives with preserved attendance')"
                trend-tone="neutral"
                icon="check-circle"
                accent="amber"
            />

            <x-bdms.stat-card
                :label="__('Total Registration')"
                :value="$summary['totalRegistrations']"
                :trend="__('Active booked donors across all drives')"
                trend-tone="up"
                icon="users"
                accent="crimson"
            />

            <x-bdms.stat-card
                :label="__('Total Collection Capacity')"
                :value="$summary['totalCapacity']"
                :trend="__('Planned donor intake slots across drives')"
                trend-tone="neutral"
                icon="droplet"
                accent="emerald"
            />
        </section>

        {{-- Camps Listing & Filters Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __('Scheduled & Community Blood Drives') }}
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Showing') }} <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($camps) }}</span> {{ __('of :total donation camps', ['total' => count($camps)]) }}
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
                                <span>{{ __('Clear all filters') }}</span>
                            </button>

                            <label for="camps-per-page" class="sr-only">{{ __('Rows per page') }}</label>
                            <select
                                id="camps-per-page"
                                x-model.number="perPage"
                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            >
                                <option value="5">{{ __('5 / page') }}</option>
                                <option value="10">{{ __('10 / page') }}</option>
                                <option value="25">{{ __('25 / page') }}</option>
                            </select>
                        </div>
                    </div>

                    {{-- Search & Filter Controls --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div class="xl:col-span-2">
                            <label for="camp-search" class="sr-only">{{ __('Search by camp ID or camp name') }}</label>
                            <x-ui.input
                                id="camp-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                :placeholder="__('Search Camp ID or camp name...')"
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="filter-camp-status" class="sr-only">{{ __('Filter by camp status') }}</label>
                            <select
                                id="filter-camp-status"
                                x-model="statusFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">{{ __('All Statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ __($status) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="filter-camp-location" class="sr-only">{{ __('Filter by location') }}</label>
                            <select
                                id="filter-camp-location"
                                x-model="locationFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">{{ __('All Locations') }}</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location }}">{{ __($location) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="camps-date-from" class="sr-only">{{ __('From start date') }}</label>
                                <input
                                    id="camps-date-from"
                                    type="date"
                                    x-model="dateFrom"
                                    title="{{ __('Filter camps ending on or after date') }}"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                            <div>
                                <label for="camps-date-to" class="sr-only">{{ __('To end date') }}</label>
                                <input
                                    id="camps-date-to"
                                    type="date"
                                    x-model="dateTo"
                                    title="{{ __('Filter camps starting on or before date') }}"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-start text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Camp ID') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Camp Name') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Location') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Start Date') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('End Date') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Start Time') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Organizer') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Capacity') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Registered Donors') }}</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">{{ __('Camp Status') }}</th>
                            <th scope="col" class="px-5 py-3.5 text-end whitespace-nowrap">{{ __('Actions') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @foreach ($camps as $index => $camp)
                            @php
                                $statusVariant = match ($camp['status']) {
                                    'Ongoing' => 'success',
                                    'Upcoming' => 'info',
                                    'Completed' => 'warning',
                                    'Cancelled' => 'danger',
                                    default => 'neutral',
                                };
                            @endphp

                            <tr
                                x-show="isRowVisible({{ $index }})"
                                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                            >
                                <td class="px-5 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200 dir-ltr text-start">
                                    <a
                                        href="{{ route('camps.show', $camp['id']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $camp['id'] }}
                                    </a>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div>
                                        <a
                                            href="{{ route('camps.show', $camp['id']) }}"
                                            class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                        >
                                            {{ __($camp['name']) }}
                                        </a>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ __($camp['venueName']) }}
                                        </p>
                                    </div>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                    {{ __($camp['city']) }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ __($camp['startDateFormatted']) }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ __($camp['endDateFormatted']) }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap font-mono text-xs text-slate-700 dark:text-slate-200 dir-ltr text-start">
                                    {{ $camp['startTime'] }} &ndash; {{ $camp['endTime'] }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                    {{ __($camp['organizerName']) }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap font-semibold text-slate-800 dark:text-slate-200">
                                    {{ $camp['capacity'] }} {{ __('slots') }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300 dir-ltr text-start">
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $camp['registeredDonors'] }}</span>
                                    <span class="text-slate-400">/ {{ $camp['capacity'] }}</span>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$statusVariant" :dot="true">
                                        {{ __($camp['status']) }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-5 py-4 text-end whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <x-ui.button variant="ghost" size="sm" :href="route('camps.show', $camp['id'])">
                                            <x-icons.icon name="eye" class="size-4" />
                                            <span>{{ __('View') }}</span>
                                        </x-ui.button>

                                        <x-ui.button variant="outline" size="sm" :href="route('camps.edit', $camp['id'])">
                                            <x-icons.icon name="pencil" class="size-3.5" />
                                            <span>{{ __('Edit') }}</span>
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
                    <x-icons.icon name="calendar" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    {{ __('No matching donation camps found') }}
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Adjust your search text, camp status, location, or date range filter to view scheduled blood drives.') }}
                </p>
                <button
                    type="button"
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-crimson-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700 cursor-pointer"
                >
                    <x-icons.icon name="x-mark" class="size-4" />
                    <span>{{ __('Clear all filters') }}</span>
                </button>
            </div>

            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Showing') }} <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingFrom"></span>
                        {{ __('to') }} <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingTo"></span>
                        {{ __('of') }} <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered"></span> {{ __('camps') }}
                    </p>

                    <nav aria-label="Donation camps pagination" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            @click="prevPage()"
                            :disabled="safePage <= 1"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            <x-icons.icon name="chevron-left" class="size-3.5 rtl-flip" />
                            <span>{{ __('Prev') }}</span>
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
                            <span>{{ __('Next') }}</span>
                            <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                        </button>
                    </nav>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-app-layout>
