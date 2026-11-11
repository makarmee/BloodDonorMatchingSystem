<x-guest-layout title="Donation Camps Directory">
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        {{-- Page Header --}}
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge variant="crimson" :dot="true">Public Community Drives</x-ui.badge>
                    <x-ui.badge variant="neutral">{{ $campSummary['totalCamps'] }} Active &amp; Upcoming Camps</x-ui.badge>
                </div>
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Public Blood Donation Camps
                </h1>
                <p class="max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                    Search published upcoming and ongoing donation camps by city, venue, or date to check remaining donor slots and walk-in policies.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <x-ui.button :href="route('public.stock')" variant="outline" size="sm">
                    <x-icons.icon name="droplet" class="size-4" />
                    Check Blood Stock
                </x-ui.button>
                <x-ui.button :href="route('donor-portal.dashboard')" variant="primary" size="sm">
                    <x-icons.icon name="heart-pulse" class="size-4" />
                    Donor Portal Preview
                </x-ui.button>
            </div>
        </div>

        {{-- Sample & Privacy Scope Notice --}}
        <x-ui.alert variant="info" title="Published Drives Only (Sample Preview)" :dismissible="false">
            Only published Upcoming and Ongoing blood drives appear here. Draft plans, completed drives, cancelled drives, and donor registration rosters are kept private. Reference preview date: {{ $todayIso }}.
        </x-ui.alert>

        {{-- Summary Stat Cards --}}
        <section aria-label="Public camps summary" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-bdms.stat-card
                label="Published Drives"
                :value="$campSummary['totalCamps']"
                trend="Upcoming & active public events"
                trend-tone="neutral"
                icon="calendar"
                accent="crimson"
            />
            <x-bdms.stat-card
                label="Ongoing Today"
                :value="$campSummary['ongoingCount']"
                trend="Collection teams on site"
                trend-tone="up"
                icon="activity"
                accent="emerald"
            />
            <x-bdms.stat-card
                label="Open for Booking"
                :value="$campSummary['openRegistrationCount']"
                trend="Accepting new donor slots"
                trend-tone="up"
                icon="check-circle"
                accent="sky"
            />
            <x-bdms.stat-card
                label="Remaining Donor Slots"
                :value="$campSummary['totalAvailableSlots']"
                trend="Synced with sample camp capacities"
                trend-tone="neutral"
                icon="users"
                accent="amber"
            />
        </section>

        {{-- Interactive Directory Filters + Paginated List --}}
        <section
            x-data="publicCampsDirectory(@js(['camps' => $camps, 'perPage' => 4]))"
            aria-labelledby="public-camps-heading"
            class="space-y-6"
        >
            <x-ui.card>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 id="public-camps-heading" class="text-base font-semibold text-slate-900 dark:text-white">
                                Search &amp; Filter Community Drives
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($camps) }}</span> matching drive(s).
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            <label class="inline-flex items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300 cursor-pointer">
                                <input
                                    type="checkbox"
                                    x-model="openOnly"
                                    class="size-4 rounded border-slate-300 text-crimson-600 focus:ring-crimson-500 dark:border-slate-700 dark:bg-slate-950"
                                />
                                Open registration / walk-ins only
                            </label>

                            <button
                                type="button"
                                x-show="hasActiveFilters"
                                x-cloak
                                @click="resetFilters()"
                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-crimson-600 hover:bg-crimson-50 dark:text-crimson-400 dark:hover:bg-crimson-950/50 cursor-pointer transition"
                            >
                                Reset
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                        <div class="lg:col-span-2 relative">
                            <x-icons.icon name="magnifying-glass" class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" />
                            <input
                                type="search"
                                x-model="search"
                                placeholder="Search city, venue, drive name, or ID..."
                                aria-label="Search donation camps"
                                class="w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 py-2 text-xs text-slate-900 placeholder:text-slate-400 focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
                            />
                        </div>

                        <select
                            x-model="cityFilter"
                            aria-label="Filter by city"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        >
                            <option value="all">All Cities</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city }}">{{ $city }}</option>
                            @endforeach
                        </select>

                        <select
                            x-model="statusFilter"
                            aria-label="Filter by camp status"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        >
                            <option value="all">All Published Statuses</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>

                        <input
                            type="date"
                            x-model="dateFrom"
                            aria-label="Filter from date"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        />

                        <select
                            x-model.number="perPage"
                            aria-label="Drives per page"
                            class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                        >
                            <option value="4">4 / page</option>
                            <option value="6">6 / page</option>
                            <option value="12">12 / page</option>
                        </select>
                    </div>
                </div>
            </x-ui.card>

            {{-- Public Camp Cards --}}
            <div class="grid gap-5 md:grid-cols-2">
                @foreach ($camps as $index => $camp)
                    @php
                        $statusVariant = $camp['status'] === 'Ongoing' ? 'success' : 'info';
                        $availabilityVariant = $camp['isBookingOpen'] ? 'crimson' : 'warning';
                        $fillPercent = $camp['capacity'] > 0
                            ? min(100, (int) round(($camp['registeredDonors'] / $camp['capacity']) * 100))
                            : 100;
                    @endphp

                    <x-ui.card
                        x-show="isCampVisible({{ $index }})"
                        class="flex flex-col justify-between gap-5"
                    >
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge variant="neutral">{{ $camp['id'] }}</x-ui.badge>
                                    <x-ui.badge :variant="$statusVariant" :dot="true">{{ $camp['status'] }}</x-ui.badge>
                                    <x-ui.badge :variant="$availabilityVariant">{{ $camp['availabilityLabel'] }}</x-ui.badge>
                                </div>

                                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                                    {{ $camp['allowWalkIn'] ? 'Walk-ins Permitted' : 'Appointments Only' }}
                                </span>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-slate-900 dark:text-white">
                                    <a href="{{ route('public.camps.show', $camp['id']) }}" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">
                                        {{ $camp['name'] }}
                                    </a>
                                </h3>
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                    {{ $camp['description'] }}
                                </p>
                            </div>

                            <dl class="grid gap-2.5 text-xs text-slate-600 sm:grid-cols-2 dark:text-slate-300">
                                <div class="flex items-start gap-2">
                                    <x-icons.icon name="calendar" class="size-4 text-slate-400 shrink-0 mt-0.5" />
                                    <div>
                                        <dt class="sr-only">Date</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $camp['scheduleSummary'] }}</dd>
                                        <dd class="text-slate-500 dark:text-slate-400">{{ $camp['startTime'] }} &ndash; {{ $camp['endTime'] }}</dd>
                                    </div>
                                </div>

                                <div class="flex items-start gap-2">
                                    <x-icons.icon name="map-pin" class="size-4 text-slate-400 shrink-0 mt-0.5" />
                                    <div>
                                        <dt class="sr-only">Venue</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">{{ $camp['venueName'] }}</dd>
                                        <dd class="text-slate-500 dark:text-slate-400">{{ $camp['fullAddress'] }}, {{ $camp['city'] }}</dd>
                                    </div>
                                </div>
                            </dl>

                            <div class="space-y-1.5 rounded-xl bg-slate-50/80 p-3 dark:bg-slate-950/60">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">
                                        Remaining Capacity
                                    </span>
                                    <span class="font-semibold tabular-nums text-slate-900 dark:text-white">
                                        {{ $camp['availableSlots'] }} / {{ $camp['capacity'] }} slots available
                                    </span>
                                </div>
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                                    <div
                                        class="h-full rounded-full {{ $camp['availableSlots'] === 0 ? 'bg-rose-500' : 'bg-emerald-500' }}"
                                        style="width: {{ $fillPercent }}%"
                                    ></div>
                                </div>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Registration deadline: {{ $camp['registrationDeadlineFormatted'] }} &bull; {{ $camp['availabilityNote'] }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                            <x-ui.button :href="route('public.camps.show', $camp['id'])" variant="outline" size="sm">
                                View Camp Details
                                <x-icons.icon name="arrow-right" class="size-3.5" />
                            </x-ui.button>

                            @if ($camp['isBookingOpen'])
                                <x-ui.button :href="route('donor-portal.appointments.create', ['camp' => $camp['id']])" variant="primary" size="sm">
                                    <x-icons.icon name="heart-pulse" class="size-3.5" />
                                    Book via Donor Portal
                                </x-ui.button>
                            @else
                                <span class="text-xs font-medium text-amber-700 dark:text-amber-300">
                                    {{ $camp['availabilityLabel'] }}
                                </span>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>

            {{-- Empty State --}}
            <x-ui.card x-show="totalFiltered === 0" x-cloak class="py-10 text-center">
                <div class="mx-auto max-w-md space-y-3">
                    <x-icons.icon name="calendar" class="mx-auto size-8 text-slate-400" />
                    <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                        No public donation camps match your criteria
                    </h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        Clear your city, date, or search filter to see all published upcoming and ongoing drives.
                    </p>
                    <div>
                        <x-ui.button variant="outline" size="sm" @click="resetFilters()">
                            Reset Filters
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.card>

            {{-- Pagination Bar --}}
            <div
                x-show="totalFiltered > 0"
                class="flex flex-col items-center justify-between gap-3 rounded-xl border border-slate-200/90 bg-white px-5 py-3.5 text-xs text-slate-600 sm:flex-row dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400"
            >
                <p>
                    Showing
                    <span class="font-semibold text-slate-900 dark:text-white" x-text="showingFrom">1</span>&ndash;<span class="font-semibold text-slate-900 dark:text-white" x-text="showingTo">{{ min(4, count($camps)) }}</span>
                    of <span class="font-semibold text-slate-900 dark:text-white" x-text="totalFiltered">{{ count($camps) }}</span> published drives
                </p>

                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        @click="prevPage()"
                        :disabled="safePage <= 1"
                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:pointer-events-none dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                    >
                        <x-icons.icon name="chevron-left" class="size-3.5" />
                        Prev
                    </button>

                    <template x-for="pageNum in totalPages" :key="pageNum">
                        <button
                            type="button"
                            @click="goToPage(pageNum)"
                            x-text="pageNum"
                            :class="pageNum === safePage
                                ? 'bg-crimson-600 text-white border-crimson-600'
                                : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800'"
                            class="size-8 rounded-lg border font-semibold tabular-nums transition cursor-pointer"
                        ></button>
                    </template>

                    <button
                        type="button"
                        @click="nextPage()"
                        :disabled="safePage >= totalPages"
                        class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40 disabled:pointer-events-none dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                    >
                        Next
                        <x-icons.icon name="chevron-right" class="size-3.5" />
                    </button>
                </div>
            </div>
        </section>
    </div>
</x-guest-layout>

