@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $appointmentsPayload = [
        'donorId' => $donorProfile['id'],
        'todayIso' => $todayIso,
        'appointments' => $appointments,
    ];
@endphp

<x-app-layout title="My Appointments — Donor Portal" page-title="My Appointments" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    My Donation Appointments
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Review upcoming bookings, inspect completed or cancelled visit records, and cancel scheduled slots.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('donor-portal.appointments.create', $donorQuery)">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Book Appointment</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="space-y-6" x-data="donorAppointments(@js($appointmentsPayload))">
        {{-- Preview Action Feedback --}}
        <div
            x-show="feedback !== null"
            x-cloak
            role="status"
            class="flex items-start justify-between gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 shadow-xs dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
        >
            <div class="flex items-start gap-3">
                <x-icons.icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div>
                    <h3 class="text-sm font-semibold" x-text="feedback?.title"></h3>
                    <p class="mt-0.5 text-sm opacity-90" x-text="feedback?.message"></p>
                </div>
            </div>

            <button
                type="button"
                @click="dismissFeedback()"
                aria-label="Dismiss message"
                class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        {{-- Upcoming / Past / All View Tabs --}}
                        <div class="inline-flex rounded-xl border border-slate-200 bg-slate-100/80 p-1 dark:border-slate-800 dark:bg-slate-950">
                            <button
                                type="button"
                                @click="viewTab = 'upcoming'"
                                :class="viewTab === 'upcoming'
                                    ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-800 dark:text-white'
                                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition cursor-pointer"
                            >
                                <span>Upcoming Appointments</span>
                                <span class="rounded-md bg-crimson-50 px-1.5 py-0.5 text-[11px] font-bold text-crimson-700 dark:bg-crimson-950/70 dark:text-crimson-300" x-text="upcomingCount"></span>
                            </button>

                            <button
                                type="button"
                                @click="viewTab = 'past'"
                                :class="viewTab === 'past'
                                    ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-800 dark:text-white'
                                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition cursor-pointer"
                            >
                                <span>Past Appointments</span>
                                <span class="rounded-md bg-slate-200/80 px-1.5 py-0.5 text-[11px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200" x-text="pastCount"></span>
                            </button>

                            <button
                                type="button"
                                @click="viewTab = 'all'"
                                :class="viewTab === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-800 dark:text-white'
                                    : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white'"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition cursor-pointer"
                            >
                                <span>All</span>
                            </button>
                        </div>

                        <button
                            type="button"
                            x-show="hasActiveFilters"
                            x-cloak
                            @click="resetFilters()"
                            class="inline-flex items-center gap-1.5 self-start rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            <x-icons.icon name="x-mark" class="size-3.5" />
                            <span>Clear filters</span>
                        </button>
                    </div>

                    {{-- Search, Status & Date Filters --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <div class="xl:col-span-2">
                            <label for="apt-search" class="sr-only">Search appointments</label>
                            <x-ui.input
                                id="apt-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search Appointment ID, location, or camp..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="apt-status-filter" class="sr-only">Filter by appointment status</label>
                            <select
                                id="apt-status-filter"
                                x-model="statusFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Statuses</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="apt-date-from" class="sr-only">From appointment date</label>
                            <input
                                id="apt-date-from"
                                type="date"
                                x-model="dateFrom"
                                title="From date"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>

                        <div>
                            <label for="apt-date-to" class="sr-only">To appointment date</label>
                            <input
                                id="apt-date-to"
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
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Appointment ID</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Donation Type</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Date</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Time</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Location</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Related Camp</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Appointment Status</th>
                            <th scope="col" class="px-5 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        <template x-for="apt in filteredAppointments" :key="apt.id">
                            <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                <td class="px-5 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200" x-text="apt.id"></td>

                                <td class="px-5 py-4 whitespace-nowrap font-medium text-slate-900 dark:text-white" x-text="apt.donationType"></td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300" x-text="apt.dateFormatted"></td>

                                <td class="px-5 py-4 whitespace-nowrap font-mono text-xs text-slate-700 dark:text-slate-200" x-text="apt.time"></td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300" x-text="apt.location"></td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    <template x-if="apt.relatedCampName">
                                        <div>
                                            <span class="font-medium text-slate-900 dark:text-white" x-text="apt.relatedCampName"></span>
                                            <span class="block font-mono text-xs text-slate-400" x-text="apt.relatedCampId"></span>
                                        </div>
                                    </template>
                                    <template x-if="!apt.relatedCampName">
                                        <span class="text-xs text-slate-400">Donation Center</span>
                                    </template>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                        :class="statusBadgeClasses(apt.status)"
                                        x-text="apt.status"
                                    ></span>
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            @click="viewDetails(apt)"
                                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                                        >
                                            <x-icons.icon name="eye" class="size-3.5" />
                                            <span>View</span>
                                        </button>

                                        <button
                                            type="button"
                                            x-show="canCancel(apt)"
                                            @click="promptCancel(apt)"
                                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-800/70 dark:bg-rose-950/60 dark:text-rose-300 cursor-pointer"
                                        >
                                            <x-icons.icon name="x-mark" class="size-3.5" />
                                            <span>Cancel</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <noscript>
                    <div class="divide-y divide-slate-200 px-5 py-3 text-sm">
                        @foreach ($appointments as $apt)
                            <div class="py-2">
                                <strong>{{ $apt['id'] }}</strong> &middot; {{ $apt['donationType'] }} &middot; {{ $apt['dateFormatted'] }} {{ $apt['time'] }} &middot; {{ $apt['location'] }} ({{ $apt['status'] }})
                            </div>
                        @endforeach
                    </div>
                </noscript>
            </div>

            {{-- Empty State --}}
            <div
                x-show="filteredAppointments.length === 0"
                x-cloak
                class="flex flex-col items-center justify-center px-6 py-14 text-center"
            >
                <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-icons.icon name="calendar" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No appointments match this view
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    Switch between Upcoming and Past appointments, adjust filters, or book a new donation visit.
                </p>
                <div class="mt-4 flex flex-wrap justify-center gap-3">
                    <button
                        type="button"
                        x-show="hasActiveFilters"
                        @click="resetFilters()"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 cursor-pointer"
                    >
                        <span>Clear Filters</span>
                    </button>

                    <x-ui.button variant="primary" size="sm" :href="route('donor-portal.appointments.create', $donorQuery)">
                        <x-icons.icon name="plus" class="size-4" />
                        <span>Book Appointment</span>
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>

        {{-- View Details Modal --}}
        <x-ui.modal name="donor-appointment-detail-modal" title="Appointment Details" max-width="md">
            <div x-show="selectedAppointment !== null" class="space-y-4 text-sm">
                <div class="flex items-center justify-between gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <span class="font-mono font-bold text-slate-900 dark:text-white" x-text="selectedAppointment?.id"></span>
                    <span
                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                        :class="statusBadgeClasses(selectedAppointment?.status)"
                        x-text="selectedAppointment?.status"
                    ></span>
                </div>

                <dl class="space-y-2.5">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Donation Type</dt>
                        <dd class="font-semibold text-slate-900 dark:text-white" x-text="selectedAppointment?.donationType"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Date &amp; Time</dt>
                        <dd class="font-semibold text-slate-900 dark:text-white" x-text="`${selectedAppointment?.dateFormatted} • ${selectedAppointment?.time}`"></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">Location</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white" x-text="selectedAppointment?.location"></dd>
                    </div>
                    <div class="flex justify-between gap-4" x-show="selectedAppointment?.relatedCampName">
                        <dt class="text-slate-500 dark:text-slate-400">Related Camp</dt>
                        <dd class="text-right font-medium text-slate-900 dark:text-white" x-text="`${selectedAppointment?.relatedCampName} (${selectedAppointment?.relatedCampId})`"></dd>
                    </div>
                </dl>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-300">
                    <p class="font-semibold text-slate-800 dark:text-slate-200">Instructions &amp; Notes</p>
                    <p class="mt-1" x-text="selectedAppointment?.prepSummary"></p>
                    <p class="mt-1 opacity-85" x-text="selectedAppointment?.notes"></p>
                </div>

                <div class="flex justify-end pt-2">
                    <button
                        type="button"
                        @click="$dispatch('close-modal', 'donor-appointment-detail-modal')"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 cursor-pointer"
                    >
                        Close
                    </button>
                </div>
            </div>
        </x-ui.modal>

        {{-- Cancel Appointment Confirmation Modal (Rule 9) --}}
        <x-ui.modal name="donor-cancel-appointment-modal" title="Confirm Appointment Cancellation" max-width="md">
            <div x-show="cancelCandidate !== null" class="space-y-4 text-sm">
                <p class="text-slate-600 dark:text-slate-300">
                    Confirm cancellation of appointment
                    <strong class="font-mono text-slate-900 dark:text-white" x-text="cancelCandidate?.id"></strong>
                    (<strong x-text="cancelCandidate?.dateFormatted"></strong> at <strong x-text="cancelCandidate?.time"></strong>)?
                    Cancelled bookings do not consume facility capacity.
                </p>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="$dispatch('close-modal', 'donor-cancel-appointment-modal')"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 cursor-pointer"
                    >
                        Keep Appointment
                    </button>
                    <button
                        type="button"
                        @click="confirmCancel()"
                        class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-rose-700 cursor-pointer"
                    >
                        Confirm Cancellation
                    </button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>

