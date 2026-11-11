@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $dashboardPayload = [
        'donorId' => $donorProfile['id'],
        'todayIso' => $todayIso,
        'appointments' => $appointments,
        'camps' => $upcomingCamps,
        'eligibility' => $eligibility,
    ];

    $nextUpcoming = $upcomingAppointments[0] ?? null;

    $eligibilityAccent = match ($eligibility['status']) {
        'Eligible' => 'emerald',
        'Not Eligible' => 'crimson',
        'Pending Review' => 'amber',
        default => 'sky',
    };
@endphp

<x-app-layout title="Donor Portal" page-title="Donor Portal Overview" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Donor Portal Overview
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Manage your scheduled appointments, review verified donation records, and check current eligibility information.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donor-portal.appointments.index', $donorQuery)">
                    <x-icons.icon name="calendar" class="size-4" />
                    <span>My Appointments</span>
                </x-ui.button>

                <x-ui.button variant="primary" size="md" :href="route('donor-portal.appointments.create', $donorQuery)">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Book Appointment</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="space-y-6" x-data="donorDashboard(@js($dashboardPayload))">
        {{-- Reactive Preview Action Feedback --}}
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
                <x-icons.icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div>
                    <h3 class="text-sm font-semibold" x-text="feedback?.title"></h3>
                    <p class="mt-0.5 text-sm opacity-90" x-text="feedback?.message"></p>
                </div>
            </div>

            <button
                type="button"
                @click="dismissFeedback()"
                aria-label="Dismiss alert"
                class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        {{-- 4 Summary Cards --}}
        <section aria-label="Donor summary statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                label="Total Donations"
                :value="$donorProfile['totalDonations']"
                trend="Lifetime logged whole blood & apheresis donations"
                trend-tone="up"
                icon="heart-pulse"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Upcoming Appointments"
                :value="count($upcomingAppointments)"
                value-bind="upcomingCount"
                trend="Active confirmed & pending scheduled visits"
                trend-tone="neutral"
                icon="calendar"
                accent="sky"
            />

            <x-bdms.stat-card
                label="Last Donation Date"
                :value="$lastDonationDisplay"
                trend="Most recent recorded donation visit"
                trend-tone="neutral"
                icon="clock"
                accent="amber"
            />

            <x-bdms.stat-card
                label="Current Eligibility Status"
                :value="$eligibility['status']"
                trend="Per latest synchronized donor record"
                trend-tone="neutral"
                icon="shield-check"
                :accent="$eligibilityAccent"
            />
        </section>

        {{-- Upcoming Appointment & Eligibility Summary --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Upcoming Appointment Card --}}
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Upcoming Appointment
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    Your next confirmed or pending scheduled donation visit.
                                </p>
                            </div>

                            <a
                                href="{{ route('donor-portal.appointments.index', $donorQuery) }}"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-crimson-600 transition hover:text-crimson-700 dark:text-crimson-400"
                            >
                                <span>Manage All</span>
                                <x-icons.icon name="chevron-right" class="size-3.5" />
                            </a>
                        </div>
                    </x-slot:header>

                    <div x-show="nextAppointment !== null" x-cloak class="space-y-4">
                        <div class="flex flex-col gap-4 rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40 sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-semibold text-slate-600 dark:text-slate-300" x-text="nextAppointment?.id"></span>
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                        :class="statusBadgeClasses(nextAppointment?.status)"
                                        x-text="nextAppointment?.status"
                                    ></span>
                                    <span class="rounded-md bg-crimson-50 px-2 py-0.5 text-xs font-semibold text-crimson-700 dark:bg-crimson-950/60 dark:text-crimson-300" x-text="nextAppointment?.donationType"></span>
                                </div>

                                <p class="text-base font-semibold text-slate-900 dark:text-white" x-text="nextAppointment?.relatedCampName || nextAppointment?.location"></p>

                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icons.icon name="calendar" class="size-4 text-slate-400" />
                                        <span x-text="nextAppointment?.dateFormatted"></span>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icons.icon name="clock" class="size-4 text-slate-400" />
                                        <span x-text="nextAppointment?.time"></span>
                                    </span>
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icons.icon name="map-pin" class="size-4 text-slate-400" />
                                        <span x-text="nextAppointment?.location"></span>
                                    </span>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    @click="viewAppointment(nextAppointment)"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 cursor-pointer"
                                >
                                    <x-icons.icon name="eye" class="size-4" />
                                    <span>View Details</span>
                                </button>

                                <button
                                    type="button"
                                    @click="promptCancel(nextAppointment)"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-800/70 dark:bg-rose-950/60 dark:text-rose-300 dark:hover:bg-rose-900/60 cursor-pointer"
                                >
                                    <x-icons.icon name="x-mark" class="size-4" />
                                    <span>Cancel Appointment</span>
                                </button>
                            </div>
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400" x-show="upcomingCount > 1">
                            You also have <strong class="text-slate-700 dark:text-slate-200" x-text="upcomingCount - 1"></strong> additional upcoming appointment(s) scheduled.
                        </p>
                    </div>

                    <div x-show="nextAppointment === null" x-cloak class="rounded-xl border border-dashed border-slate-300 px-6 py-8 text-center dark:border-slate-700">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">No upcoming appointments scheduled</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Reserve a time slot at an open donation camp or fixed blood bank suite.
                        </p>
                        <div class="mt-4">
                            <x-ui.button variant="primary" size="sm" :href="route('donor-portal.appointments.create', $donorQuery)">
                                <x-icons.icon name="plus" class="size-4" />
                                <span>Book Appointment</span>
                            </x-ui.button>
                        </div>
                    </div>

                    <noscript>
                        @if ($nextUpcoming)
                            <div class="rounded-xl border border-slate-200 p-4">
                                <p class="font-semibold">{{ $nextUpcoming['id'] }} &middot; {{ $nextUpcoming['status'] }}</p>
                                <p class="text-sm">{{ $nextUpcoming['dateFormatted'] }} at {{ $nextUpcoming['time'] }} &mdash; {{ $nextUpcoming['location'] }}</p>
                            </div>
                        @else
                            <p class="text-sm text-slate-500">No upcoming appointments scheduled.</p>
                        @endif
                    </noscript>
                </x-ui.card>
            </div>

            {{-- Eligibility Summary Card --}}
            <div>
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Eligibility Summary
                            </h2>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                :class="eligibilityBadgeClasses(eligibility.status)"
                            >
                                {{ $eligibility['status'] }}
                            </span>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4">
                        <p class="text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                            {{ $eligibility['reason'] }}
                        </p>

                        <dl class="space-y-2 rounded-xl border border-slate-200/80 bg-slate-50/70 p-3.5 text-xs dark:border-slate-800 dark:bg-slate-800/40">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-slate-500 dark:text-slate-400">Last Reviewed</dt>
                                <dd class="font-semibold text-slate-900 dark:text-white">{{ $eligibility['lastReviewedDate'] ?? 'Not recorded' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <dt class="text-slate-500 dark:text-slate-400">Next Eligible Date</dt>
                                <dd class="font-semibold text-slate-900 dark:text-white">{{ $eligibility['nextEligibleDate'] ?? 'Per clinical review' }}</dd>
                            </div>
                        </dl>

                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            Final donation eligibility must be confirmed by authorized medical staff prior to collection.
                        </p>

                        <x-ui.button variant="outline" size="sm" class="w-full justify-center" :href="route('donor-portal.eligibility', $donorQuery)">
                            <x-icons.icon name="shield-check" class="size-4" />
                            <span>View Eligibility Details</span>
                        </x-ui.button>
                    </div>
                </x-ui.card>
            </div>
        </div>

        {{-- Recent Donation Activity & Nearby / Upcoming Camps --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Recent Donation Activity --}}
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Recent Donation Activity
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Your recent donations, collection dates, and screening progress.
                            </p>
                        </div>

                        <a
                            href="{{ route('donor-portal.history', $donorQuery) }}"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-crimson-600 transition hover:text-crimson-700 dark:text-crimson-400"
                        >
                            <span>Full History</span>
                            <x-icons.icon name="chevron-right" class="size-3.5" />
                        </a>
                    </div>
                </x-slot:header>

                @if (count($recentDonations) > 0)
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        @foreach ($recentDonations as $donation)
                            @php
                                $statusVariant = match ($donation['collectionStatus']) {
                                    'Completed', 'Cleared' => 'success',
                                    'Scheduled' => 'info',
                                    'Cancelled' => 'neutral',
                                    default => 'warning',
                                };
                            @endphp

                            <div class="flex items-center justify-between gap-4 px-5 py-4">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200">
                                            {{ $donation['id'] }}
                                        </span>
                                        <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="sm" />
                                        <x-ui.badge :variant="$statusVariant">
                                            {{ $donation['collectionStatus'] }}
                                        </x-ui.badge>
                                    </div>
                                    <p class="truncate text-sm font-medium text-slate-900 dark:text-white">
                                        {{ $donation['collectionLocation'] }}
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $donation['donationType'] }} &middot; Screening: {{ $donation['screeningStatus'] }}
                                    </p>
                                </div>

                                <span class="shrink-0 text-xs font-medium text-slate-500 dark:text-slate-400">
                                    {{ $donation['donationDateFormatted'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        No past donation records found for this donor account.
                    </div>
                @endif
            </x-ui.card>

            {{-- Nearby / Upcoming Donation Camps --}}
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Nearby / Upcoming Donation Camps
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Open and ongoing community blood drives synchronized with camp scheduling.
                            </p>
                        </div>

                        <a
                            href="{{ route('donor-portal.appointments.create', $donorQuery) }}"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-crimson-600 transition hover:text-crimson-700 dark:text-crimson-400"
                        >
                            <span>Book Slot</span>
                            <x-icons.icon name="chevron-right" class="size-3.5" />
                        </a>
                    </div>
                </x-slot:header>

                <div class="divide-y divide-slate-200 dark:divide-slate-800">
                    @foreach ($upcomingCamps as $camp)
                        @php
                            $campVariant = $camp['status'] === 'Ongoing' ? 'success' : 'info';
                        @endphp

                        <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        {{ $camp['id'] }}
                                    </span>
                                    <x-ui.badge :variant="$campVariant" :dot="true">
                                        {{ $camp['status'] }}
                                    </x-ui.badge>
                                </div>

                                <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">
                                    {{ $camp['name'] }}
                                </p>

                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $camp['venueName'] }}, {{ $camp['city'] }} &middot; {{ $camp['startDateFormatted'] }} &middot;
                                    <strong class="text-slate-700 dark:text-slate-200">{{ $camp['availableSlots'] }} / {{ $camp['capacity'] }} slots available</strong>
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    @click="viewCamp(@js($camp))"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                                >
                                    <x-icons.icon name="eye" class="size-3.5" />
                                    <span>View Camp Details</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        {{-- View Appointment Details Modal --}}
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
                        <dt class="text-slate-500 dark:text-slate-400">Scheduled Date &amp; Time</dt>
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
                    <p class="font-semibold text-slate-800 dark:text-slate-200">Preparation &amp; Notes</p>
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
                    Are you sure you want to cancel appointment
                    <strong class="font-mono text-slate-900 dark:text-white" x-text="cancelCandidate?.id"></strong>
                    on <strong class="text-slate-900 dark:text-white" x-text="cancelCandidate?.dateFormatted"></strong>
                    at <strong class="text-slate-900 dark:text-white" x-text="cancelCandidate?.time"></strong>?
                    This preview action frees the time slot immediately.
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

        {{-- Donor-Safe Camp Details Modal (Zero Staff Actions Exposed) --}}
        <x-ui.modal name="donor-camp-detail-modal" title="Donation Camp Information" max-width="lg">
            <div x-show="selectedCamp !== null" class="space-y-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div>
                        <span class="font-mono text-xs font-semibold text-slate-500 dark:text-slate-400" x-text="selectedCamp?.id"></span>
                        <h3 class="text-base font-bold text-slate-900 dark:text-white" x-text="selectedCamp?.name"></h3>
                    </div>
                    <span class="rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70" x-text="selectedCamp?.status"></span>
                </div>

                <p class="text-slate-600 dark:text-slate-300" x-text="selectedCamp?.description"></p>

                <dl class="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-xs dark:border-slate-800 dark:bg-slate-800/40 sm:grid-cols-2">
                    <div>
                        <dt class="font-medium text-slate-500 dark:text-slate-400">Venue &amp; Address</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="selectedCamp?.venueName"></dd>
                        <dd class="text-slate-600 dark:text-slate-300" x-text="`${selectedCamp?.fullAddress}, ${selectedCamp?.city}`"></dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500 dark:text-slate-400">Schedule &amp; Deadline</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="`${selectedCamp?.startDateFormatted} – ${selectedCamp?.endDateFormatted}`"></dd>
                        <dd class="text-slate-600 dark:text-slate-300" x-text="`Hours: ${selectedCamp?.startTime} – ${selectedCamp?.endTime}`"></dd>
                        <dd class="text-slate-600 dark:text-slate-300" x-text="`Registration deadline: ${selectedCamp?.registrationDeadlineFormatted}`"></dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500 dark:text-slate-400">Available Capacity</dt>
                        <dd class="mt-0.5 font-semibold text-emerald-600 dark:text-emerald-400" x-text="`${selectedCamp?.availableSlots} of ${selectedCamp?.capacity} slots available`"></dd>
                    </div>
                    <div>
                        <dt class="font-medium text-slate-500 dark:text-slate-400">Walk-in Policy</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900 dark:text-white" x-text="selectedCamp?.allowWalkIn ? 'Walk-in donors permitted if ongoing' : 'Pre-registered donors only'"></dd>
                    </div>
                </dl>

                <div class="rounded-xl border border-slate-200 p-3.5 text-xs dark:border-slate-800">
                    <p class="font-semibold text-slate-800 dark:text-slate-200">Donor Requirements &amp; Access Notes</p>
                    <p class="mt-1 text-slate-600 dark:text-slate-300" x-text="selectedCamp?.donorRequirements"></p>
                    <p class="mt-1 text-slate-500 dark:text-slate-400" x-show="selectedCamp?.locationNotes" x-text="selectedCamp?.locationNotes"></p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        @click="$dispatch('close-modal', 'donor-camp-detail-modal')"
                        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 cursor-pointer"
                    >
                        Close
                    </button>
                    <a
                        :href="@js(route('donor-portal.appointments.create', array_merge($donorQuery, ['camp' => '__CAMP__']))).replace('__CAMP__', encodeURIComponent(selectedCamp?.id || ''))"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-crimson-600 px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-crimson-700"
                    >
                        <x-icons.icon name="calendar" class="size-4" />
                        <span>Book Appointment at Camp</span>
                    </a>
                </div>
            </div>
        </x-ui.modal>
    </div>
</x-app-layout>

