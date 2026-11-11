@php
    $initialPercent = $camp['capacity'] > 0
        ? (int) min(100, round(($camp['registeredDonors'] / $camp['capacity']) * 100))
        : 0;

    $statusVariant = match ($camp['status']) {
        'Ongoing' => 'success',
        'Upcoming' => 'info',
        'Completed' => 'warning',
        'Cancelled' => 'danger',
        default => 'neutral',
    };

    $donorShowUrlTemplate = route('donors.show', '__DONOR__');

    $registrationPayload = [
        'camp' => $camp,
        'donors' => $donors,
        'todayIso' => '2026-11-11',
    ];
@endphp

<x-app-layout :title="'Donation Camp · ' . $camp['id']" page-title="Camp Details" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('camps.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Donation Camps
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="font-mono text-slate-700 dark:text-slate-200">{{ $camp['id'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $camp['name'] }}
                    </h1>
                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 font-mono text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ $camp['id'] }}
                    </span>
                    <x-ui.badge :variant="$statusVariant" :dot="true">
                        {{ $camp['status'] }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ $camp['venueName'] }}, {{ $camp['city'] }} &middot; {{ $camp['startDateFormatted'] }} to {{ $camp['endDateFormatted'] }} &middot; {{ $camp['startTime'] }}&ndash;{{ $camp['endTime'] }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('camps.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Camps</span>
                </x-ui.button>

                <x-ui.button variant="primary" size="md" :href="route('camps.edit', $camp['id'])">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>Edit Camp</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="campDetails({ camp: @js($camp) })">
        {{-- Reactive Camp Details Feedback Banner --}}
        <div
            x-show="feedback !== null"
            x-cloak
            role="status"
            :class="feedback?.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
                : 'border-crimson-200 bg-crimson-50 text-crimson-900 dark:border-crimson-500/30 dark:bg-crimson-500/10 dark:text-crimson-200'"
            class="flex items-start justify-between gap-4 rounded-2xl border p-4 shadow-xs"
        >
            <div class="flex items-start gap-3">
                <span
                    :class="feedback?.type === 'success'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-crimson-600 dark:text-crimson-400'"
                    class="mt-0.5 shrink-0"
                >
                    <template x-if="feedback?.type === 'success'">
                        <x-icons.icon name="check-circle" class="size-5" />
                    </template>
                    <template x-if="feedback?.type !== 'success'">
                        <x-icons.icon name="exclamation-triangle" class="size-5" />
                    </template>
                </span>

                <div>
                    <h3 class="text-sm font-semibold" x-text="feedback?.title"></h3>
                    <p class="mt-0.5 text-sm opacity-90" x-text="feedback?.message"></p>
                </div>
            </div>

            <button
                type="button"
                @click="dismissFeedback()"
                aria-label="Dismiss status message"
                class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Left Column (2/3): Camp Overview, Registrations List, Activity History --}}
            <div class="space-y-6 xl:col-span-2">
                {{-- Section A: Camp Overview --}}
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Camp Overview &amp; Operations
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    Venue details, schedule window, coordinator contact, and preview status controls.
                                </p>
                            </div>

                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset"
                                :class="statusBadgeClasses(status)"
                                x-text="status"
                            >{{ $camp['status'] }}</span>
                        </div>
                    </x-slot:header>

                    <div class="space-y-6">
                        <p class="text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ $camp['description'] }}
                        </p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Location &amp; Venue
                                </p>
                                <p class="mt-1.5 text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $camp['venueName'] }}
                                </p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">
                                    {{ $camp['fullAddress'] }}
                                </p>
                                <p class="mt-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    City: {{ $camp['city'] }}
                                </p>
                                @if (! empty($camp['locationNotes']))
                                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                        {{ $camp['locationNotes'] }}
                                    </p>
                                @endif
                            </div>

                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Schedule &amp; Organizer
                                </p>
                                <dl class="mt-1.5 space-y-1.5 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-slate-500 dark:text-slate-400">Dates</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">
                                            {{ $camp['startDateFormatted'] }} &ndash; {{ $camp['endDateFormatted'] }}
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-slate-500 dark:text-slate-400">Hours</dt>
                                        <dd class="font-mono text-xs font-semibold text-slate-900 dark:text-white">
                                            {{ $camp['startTime'] }} &ndash; {{ $camp['endTime'] }}
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-slate-500 dark:text-slate-400">Organizer</dt>
                                        <dd class="font-medium text-slate-900 dark:text-white">
                                            {{ $camp['organizerName'] }}
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-slate-500 dark:text-slate-400">Phone</dt>
                                        <dd class="text-slate-700 dark:text-slate-300">
                                            {{ $camp['organizerContact'] }}
                                        </dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                                        <dd class="truncate text-slate-700 dark:text-slate-300">
                                            {{ $camp['organizerEmail'] }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Requirements for Donors
                                </p>
                                <p class="mt-1.5 text-sm text-slate-700 dark:text-slate-300">
                                    {{ $camp['donorRequirements'] }}
                                </p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Walk-in Policy &amp; Notes
                                </p>
                                <p class="mt-1.5 text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $camp['allowWalkIn'] ? 'Walk-in donors allowed during Ongoing status' : 'Pre-registered donors only (no walk-ins)' }}
                                </p>
                                @if (! empty($camp['additionalNotes']))
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        {{ $camp['additionalNotes'] }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Preview Status Transition Actions --}}
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-4 dark:border-slate-700 dark:bg-slate-800/30">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold tracking-wider text-slate-600 uppercase dark:text-slate-300">
                                        Camp Lifecycle Status Actions (Preview)
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        Switch status in preview mode. Completing or cancelling a camp retains all donor registration history.
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($campStatuses as $campStatusOption)
                                        <button
                                            type="button"
                                            @click="changeCampStatus(@js($campStatusOption))"
                                            :class="status === @js($campStatusOption)
                                                ? 'bg-crimson-600 text-white border-crimson-600 shadow-xs'
                                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800'"
                                            class="rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition cursor-pointer"
                                        >
                                            {{ $campStatusOption }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </x-ui.card>

                {{-- Section C: Donor Registration List --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                        Donor Registration List
                                    </h2>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="activeRegistrationsCount">{{ $camp['registeredDonors'] }}</span> active /
                                        <span x-text="registrations.length">{{ count($camp['registrations']) }}</span> total records (cancelled registrations excluded from active capacity)
                                    </p>
                                </div>

                                <x-ui.badge variant="neutral">
                                    <span x-text="filteredRegistrations.length">{{ count($camp['registrations']) }}</span> shown
                                </x-ui.badge>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="sm:col-span-2">
                                    <label for="camp-reg-search" class="sr-only">Search registrations</label>
                                    <x-ui.input
                                        id="camp-reg-search"
                                        name="reg_search"
                                        type="search"
                                        icon="magnifying-glass"
                                        placeholder="Search Registration ID, Donor Name, or Donor ID..."
                                        x-model.debounce.150ms="registrationSearch"
                                    />
                                </div>

                                <div>
                                    <label for="camp-reg-attendance-filter" class="sr-only">Filter by attendance status</label>
                                    <select
                                        id="camp-reg-attendance-filter"
                                        x-model="attendanceFilter"
                                        class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                        <option value="all">All Attendance Statuses</option>
                                        @foreach ($attendanceStatuses as $attStatus)
                                            <option value="{{ $attStatus }}">{{ $attStatus }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                            <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                                <tr>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Registration ID</th>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Donor Name</th>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Donor ID</th>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Blood Group</th>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Registration Date</th>
                                    <th scope="col" class="px-5 py-3 whitespace-nowrap">Attendance Status</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                <template x-for="reg in filteredRegistrations" :key="reg.id">
                                    <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                        <td class="px-5 py-3.5 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200" x-text="reg.id"></td>

                                        <td class="px-5 py-3.5 whitespace-nowrap">
                                            <a
                                                :href="@js($donorShowUrlTemplate).replace('__DONOR__', encodeURIComponent(reg.donorId))"
                                                class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                                x-text="reg.donorName"
                                            ></a>
                                        </td>

                                        <td class="px-5 py-3.5 font-mono text-xs whitespace-nowrap text-slate-600 dark:text-slate-300">
                                            <a
                                                :href="@js($donorShowUrlTemplate).replace('__DONOR__', encodeURIComponent(reg.donorId))"
                                                class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                                x-text="reg.donorId"
                                            ></a>
                                        </td>

                                        <td class="px-5 py-3.5 whitespace-nowrap">
                                            <span
                                                class="inline-flex items-center rounded-lg bg-crimson-50 px-2.5 py-0.5 text-xs font-bold text-crimson-700 ring-1 ring-inset ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800/70"
                                                x-text="reg.bloodGroup"
                                            ></span>
                                        </td>

                                        <td class="px-5 py-3.5 whitespace-nowrap text-slate-600 dark:text-slate-300" x-text="reg.registrationDate"></td>

                                        <td class="px-5 py-3.5 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                                    :class="attendanceBadgeClasses(reg.attendanceStatus)"
                                                    x-text="reg.attendanceStatus"
                                                ></span>

                                                <select
                                                    :aria-label="'Update attendance for ' + reg.donorName"
                                                    :value="reg.attendanceStatus"
                                                    @change="updateAttendanceStatus(reg.id, $event.target.value)"
                                                    class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-medium text-slate-700 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
                                                >
                                                    @foreach ($attendanceStatuses as $attStatus)
                                                        <option value="{{ $attStatus }}">{{ $attStatus }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>

                        {{-- Server-rendered donor registration fallback for non-JS assertions & SEO --}}
                        <noscript>
                            <div class="divide-y divide-slate-200 px-5 py-3 text-sm">
                                @foreach ($camp['registrations'] as $reg)
                                    <div class="flex items-center justify-between gap-4 py-2">
                                        <span>
                                            <strong>{{ $reg['id'] }}</strong> &middot;
                                            <a href="{{ route('donors.show', $reg['donorId']) }}">{{ $reg['donorName'] }}</a>
                                            ({{ $reg['donorId'] }}, {{ $reg['bloodGroup'] }})
                                        </span>
                                        <span>{{ $reg['registrationDate'] }} &middot; {{ $reg['attendanceStatus'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </noscript>
                    </div>

                    <div
                        x-show="filteredRegistrations.length === 0"
                        x-cloak
                        class="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400"
                    >
                        No donor registrations match your current search or attendance filter.
                    </div>
                </x-ui.card>

                {{-- Section E: Camp Activity / History --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Camp Activity &amp; History
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Chronological timeline of schedule creation, registrations, attendance checks, and lifecycle updates.
                                </p>
                            </div>

                            <x-ui.badge variant="neutral">
                                <span x-text="history.length">{{ count($camp['history']) }}</span> events
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        <template x-for="(entry, idx) in history" :key="idx">
                            <div class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="space-y-1">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white" x-text="entry.event"></p>
                                    <p class="text-sm text-slate-600 dark:text-slate-300" x-text="entry.notes"></p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Actor: <span class="font-medium text-slate-700 dark:text-slate-200" x-text="entry.actor"></span>
                                    </p>
                                </div>

                                <span class="shrink-0 text-xs font-medium text-slate-500 dark:text-slate-400" x-text="entry.date"></span>
                            </div>
                        </template>

                        {{-- Server-rendered history fallback for non-JS assertions & SEO --}}
                        <noscript>
                            @foreach ($camp['history'] as $entry)
                                <div class="px-6 py-4">
                                    <p class="font-semibold text-slate-900">{{ $entry['event'] }} &mdash; {{ $entry['date'] }}</p>
                                    <p class="text-sm text-slate-600">{{ $entry['notes'] }} ({{ $entry['actor'] }})</p>
                                </div>
                            @endforeach
                        </noscript>
                    </div>
                </x-ui.card>
            </div>

            {{-- Right Column (1/3): Capacity Overview & Registration Management --}}
            <div class="space-y-6">
                {{-- Section B: Capacity Overview --}}
                <x-ui.card>
                    <x-slot:header>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Capacity Overview
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Live slot allocation excluding cancelled bookings.
                            </p>
                        </div>
                    </x-slot:header>

                    <div class="space-y-5">
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-[11px] font-medium text-slate-500 uppercase dark:text-slate-400">Maximum Capacity</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white" x-text="capacity">{{ $camp['capacity'] }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-[11px] font-medium text-slate-500 uppercase dark:text-slate-400">Registered Donors</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-crimson-600 dark:text-crimson-400" x-text="activeRegistrationsCount">{{ $camp['registeredDonors'] }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-[11px] font-medium text-slate-500 uppercase dark:text-slate-400">Available Slots</p>
                                <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400" x-text="availableSlots">{{ $camp['availableSlots'] }}</p>
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between text-xs font-medium">
                                <span class="text-slate-600 dark:text-slate-300">Registration Progress</span>
                                <span class="font-semibold tabular-nums text-slate-900 dark:text-white" x-text="progressPercent + '%'">{{ $initialPercent }}%</span>
                            </div>
                            <div
                                role="progressbar"
                                aria-label="Camp registration progress"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                :aria-valuenow="progressPercent"
                                class="h-2.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800"
                            >
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="availableSlots === 0 ? 'bg-amber-500' : 'bg-crimson-600'"
                                    style="width: {{ $initialPercent }}%"
                                    :style="`width: ${progressPercent}%`"
                                ></div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between rounded-xl border border-slate-200/80 px-4 py-3 text-sm dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400">Registration Deadline</span>
                            <span class="font-semibold text-slate-900 dark:text-white">{{ $camp['registrationDeadlineFormatted'] }}</span>
                        </div>
                    </div>
                </x-ui.card>

                {{-- Section D: Registration Management --}}
                <div x-data="campRegistration(@js($registrationPayload))">
                    <x-ui.card>
                        <x-slot:header>
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Registration Management
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    Assign an existing registered sample donor to this camp in preview mode.
                                </p>
                            </div>
                        </x-slot:header>

                        <div class="space-y-4">
                            {{-- Inner Registration Feedback Banner --}}
                            <div
                                x-show="feedback !== null"
                                x-cloak
                                role="status"
                                :class="feedback?.type === 'success'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
                                    : 'border-crimson-200 bg-crimson-50 text-crimson-900 dark:border-crimson-500/30 dark:bg-crimson-500/10 dark:text-crimson-200'"
                                class="flex items-start justify-between gap-3 rounded-xl border p-3.5 text-xs"
                            >
                                <div>
                                    <p class="font-semibold" x-text="feedback?.title"></p>
                                    <p class="mt-0.5 opacity-90" x-text="feedback?.message"></p>
                                </div>
                                <button type="button" @click="dismissFeedback()" aria-label="Dismiss registration alert" class="opacity-70 hover:opacity-100 cursor-pointer">
                                    <x-icons.icon name="x-mark" class="size-4" />
                                </button>
                            </div>

                            {{-- Guard / Eligibility Notice --}}
                            <div
                                x-show="!eligibility.allowed"
                                x-cloak
                                class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/60 dark:text-amber-200"
                            >
                                <x-icons.icon name="exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <p x-text="eligibility.reason"></p>
                            </div>

                            <form @submit.prevent="registerDonor()" class="space-y-4">
                                <div class="relative">
                                    <label for="camp-donor-search" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Select Sample Donor <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>

                                    <div class="mt-1.5">
                                        <input
                                            id="camp-donor-search"
                                            type="text"
                                            autocomplete="off"
                                            x-model="donorSearch"
                                            @focus="donorDropdownOpen = true"
                                            @click="donorDropdownOpen = true"
                                            @click.outside="donorDropdownOpen = false"
                                            placeholder="Search by donor name, ID, or blood group..."
                                            class="block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                                        />
                                    </div>

                                    {{-- Searchable Dropdown List --}}
                                    <div
                                        x-show="donorDropdownOpen"
                                        x-cloak
                                        class="absolute z-20 mt-1.5 max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                                    >
                                        <template x-for="donor in filteredDonors" :key="donor.id">
                                            <button
                                                type="button"
                                                @click="selectDonor(donor.id)"
                                                class="flex w-full items-center justify-between gap-2 px-3.5 py-2 text-left text-sm transition hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer"
                                            >
                                                <div class="min-w-0">
                                                    <p class="truncate font-medium text-slate-900 dark:text-white" x-text="donor.name"></p>
                                                    <p class="font-mono text-xs text-slate-500 dark:text-slate-400" x-text="donor.id"></p>
                                                </div>
                                                <span
                                                    class="shrink-0 rounded-md bg-crimson-50 px-2 py-0.5 text-xs font-bold text-crimson-700 dark:bg-crimson-950/70 dark:text-crimson-300"
                                                    x-text="donor.bloodGroup"
                                                ></span>
                                            </button>
                                        </template>

                                        <div
                                            x-show="filteredDonors.length === 0"
                                            class="px-3.5 py-3 text-center text-xs text-slate-500 dark:text-slate-400"
                                        >
                                            No matching sample donors found.
                                        </div>
                                    </div>

                                    <p
                                        x-show="errors.donorId"
                                        x-cloak
                                        x-text="errors.donorId"
                                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>

                                {{-- Auto-displayed Associated Donor ID & Blood Group --}}
                                <div
                                    x-show="selectedDonor !== null"
                                    x-cloak
                                    class="rounded-xl border border-slate-200 bg-slate-50/80 p-3.5 dark:border-slate-700 dark:bg-slate-800/50"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                                Selected Donor Summary
                                            </p>
                                            <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" x-text="selectedDonor?.name"></p>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                                <span>Donor ID: <strong class="font-mono text-slate-900 dark:text-white" x-text="selectedDonor?.id"></strong></span>
                                                <span>&middot;</span>
                                                <span>Blood Group: <strong class="text-crimson-600 dark:text-crimson-400" x-text="selectedDonor?.bloodGroup"></strong></span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            @click="selectedDonorId = ''; errors = {};"
                                            aria-label="Clear selected donor"
                                            class="rounded-lg p-1 text-slate-400 hover:bg-slate-200/70 hover:text-slate-700 dark:hover:bg-slate-700 dark:hover:text-slate-200 cursor-pointer"
                                        >
                                            <x-icons.icon name="x-mark" class="size-4" />
                                        </button>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="selectedDonorId !== '' && !eligibility.allowed"
                                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-crimson-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700 disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer"
                                >
                                    <x-icons.icon name="plus" class="size-4" />
                                    <span>Register Donor to Camp</span>
                                </button>
                            </form>
                        </div>
                    </x-ui.card>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>

