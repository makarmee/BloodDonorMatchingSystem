@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $bookingPayload = [
        'donorId' => $donorProfile['id'],
        'initialAppointments' => $appointments,
        'camps' => $camps,
        'centers' => $centers,
        'timeSlotTemplates' => $timeSlotTemplates,
        'donationTypes' => $donationTypes,
        'preselectedCampId' => $preselectedCampId,
        'todayIso' => $todayIso,
    ];
@endphp

<x-app-layout title="Book Appointment — Donor Portal" page-title="Book Appointment" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donor-portal.appointments.index', $donorQuery) }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        My Appointments
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Book Appointment</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Book Donation Appointment
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Select a donation type, open donation camp or fixed center, available date, and sample time slot.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('donor-portal.appointments.index', $donorQuery)">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Appointments</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="mx-auto max-w-5xl space-y-6" x-data="appointmentForm(@js($bookingPayload))">
        @if ($eligibility['status'] !== 'Eligible')
            <x-ui.alert variant="warning" title="Eligibility Advisory ({{ $eligibility['status'] }})">
                Your synchronized record currently shows <strong>{{ $eligibility['status'] }}</strong>. Any appointment preview submitted here requires final confirmation by authorized blood bank medical staff.
            </x-ui.alert>
        @endif

        {{-- Reactive Preview Feedback Banner --}}
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
                    <div x-show="feedback?.type === 'success'" class="mt-2.5 flex flex-wrap gap-3 text-xs font-semibold">
                        <a href="{{ route('donor-portal.appointments.index', $donorQuery) }}" class="underline hover:opacity-80">
                            View in My Appointments &rarr;
                        </a>
                        <a href="{{ route('donor-portal.dashboard', $donorQuery) }}" class="underline hover:opacity-80">
                            Return to Donor Overview &rarr;
                        </a>
                    </div>
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

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Booking Form --}}
            <div class="lg:col-span-2">
                <form @submit.prevent="submitForm()" novalidate>
                    <x-ui.card>
                        <x-slot:header>
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Appointment Scheduling Details
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    Duplicate date/time bookings, past dates, closed camps, and full slots are automatically blocked.
                                </p>
                            </div>
                        </x-slot:header>

                        <div class="space-y-5">
                            <div>
                                <label for="booking-donation-type" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                    Donation Type <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                </label>
                                <select
                                    id="booking-donation-type"
                                    name="donation_type"
                                    x-model="form.donationType"
                                    @change="validateCurrentSelection()"
                                    class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                    @foreach ($donationTypes as $type)
                                        <option value="{{ $type }}">{{ $type }}</option>
                                    @endforeach
                                </select>
                                <p
                                    x-show="errors.donationType"
                                    x-cloak
                                    x-text="errors.donationType"
                                    class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                ></p>
                            </div>

                            <div>
                                <label for="booking-location" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                    Donation Camp or Donation Center <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                </label>
                                <select
                                    id="booking-location"
                                    name="location"
                                    x-model="form.locationSelection"
                                    @change="onLocationChange()"
                                    class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                    <optgroup label="Permanent Donation Centers">
                                        @foreach ($centers as $center)
                                            <option value="center:{{ $center['id'] }}">
                                                {{ $center['name'] }} ({{ $center['city'] }})
                                            </option>
                                        @endforeach
                                    </optgroup>

                                    <optgroup label="Donation Camps (Sample Schedule)">
                                        @foreach ($camps as $camp)
                                            <option value="camp:{{ $camp['id'] }}">
                                                {{ $camp['name'] }} &mdash; {{ $camp['startDateFormatted'] }} [{{ $camp['status'] }}, {{ $camp['availableSlots'] }} slots left]
                                            </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                                <p
                                    x-show="errors.location"
                                    x-cloak
                                    x-text="errors.location"
                                    class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                ></p>
                            </div>

                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="booking-date" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Available Date <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>
                                    <input
                                        id="booking-date"
                                        name="date"
                                        type="date"
                                        min="{{ $todayIso }}"
                                        x-model="form.date"
                                        @change="onDateChange()"
                                        class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    />
                                    <p
                                        x-show="errors.date"
                                        x-cloak
                                        x-text="errors.date"
                                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>

                                <div>
                                    <label for="booking-time" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Available Time Slot <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>
                                    <select
                                        id="booking-time"
                                        name="time"
                                        x-model="form.time"
                                        @change="validateCurrentSelection()"
                                        class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                        <template x-for="slot in availableTimeSlots" :key="slot.time">
                                            <option :value="slot.time" x-text="`${slot.time} (${slot.remaining} slot${slot.remaining === 1 ? '' : 's'} left)`"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                        Only available sample time slots are listed. Full or passed slots are excluded.
                                    </p>
                                    <p
                                        x-show="errors.time"
                                        x-cloak
                                        x-text="errors.time"
                                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>
                            </div>

                            <div>
                                <x-ui.textarea
                                    id="booking-notes"
                                    name="notes"
                                    label="Additional Notes"
                                    rows="3"
                                    placeholder="Optional arrival timing preferences or donor comfort requests..."
                                    hint="Optional preview notes (up to 500 characters)."
                                    x-model="form.notes"
                                />
                            </div>
                        </div>

                        <x-slot:footer>
                            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                                <x-ui.button variant="outline" size="md" :href="route('donor-portal.appointments.index', $donorQuery)">
                                    Cancel
                                </x-ui.button>

                                <x-ui.button variant="primary" size="md" type="submit">
                                    <x-icons.icon name="check" class="size-4" />
                                    <span>Confirm Booking (Preview)</span>
                                </x-ui.button>
                            </div>
                        </x-slot:footer>
                    </x-ui.card>
                </form>
            </div>

            {{-- Dynamic Selected Camp / Center Details Panel --}}
            <div class="space-y-6">
                <template x-if="selectedCamp !== null">
                    <x-ui.card>
                        <x-slot:header>
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Selected Camp Information
                                </h2>
                                <span
                                    class="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                    x-text="selectedCamp.id"
                                ></span>
                            </div>
                        </x-slot:header>

                        <div class="space-y-4 text-xs">
                            <div>
                                <p class="text-sm font-bold text-slate-900 dark:text-white" x-text="selectedCamp.name"></p>
                                <p class="mt-1 text-slate-600 dark:text-slate-300" x-text="selectedCamp.description"></p>
                            </div>

                            <dl class="space-y-2 rounded-xl border border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-800 dark:bg-slate-800/40">
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Status</dt>
                                    <dd class="font-semibold text-slate-900 dark:text-white" x-text="selectedCamp.status"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Venue</dt>
                                    <dd class="text-right font-semibold text-slate-900 dark:text-white" x-text="selectedCamp.venueName"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Address</dt>
                                    <dd class="text-right text-slate-700 dark:text-slate-300" x-text="`${selectedCamp.fullAddress}, ${selectedCamp.city}`"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Camp Dates</dt>
                                    <dd class="font-semibold text-slate-900 dark:text-white" x-text="`${selectedCamp.startDateFormatted} – ${selectedCamp.endDateFormatted}`"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Hours</dt>
                                    <dd class="font-mono font-semibold text-slate-900 dark:text-white" x-text="`${selectedCamp.startTime} – ${selectedCamp.endTime}`"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Registration Deadline</dt>
                                    <dd class="font-semibold text-slate-900 dark:text-white" x-text="selectedCamp.registrationDeadlineFormatted"></dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Available Slots</dt>
                                    <dd
                                        class="font-semibold"
                                        :class="selectedCamp.availableSlots > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
                                        x-text="`${selectedCamp.availableSlots} / ${selectedCamp.capacity} slots`"
                                    ></dd>
                                </div>
                            </dl>

                            <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                                <p class="font-semibold text-slate-800 dark:text-slate-200">Donor Requirements</p>
                                <p class="mt-1 text-slate-600 dark:text-slate-300" x-text="selectedCamp.donorRequirements"></p>
                            </div>
                        </div>
                    </x-ui.card>
                </template>

                <template x-if="selectedCenter !== null">
                    <x-ui.card>
                        <x-slot:header>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Selected Donation Center
                            </h2>
                        </x-slot:header>

                        <div class="space-y-3 text-xs">
                            <p class="text-sm font-bold text-slate-900 dark:text-white" x-text="selectedCenter.name"></p>
                            <p class="text-slate-600 dark:text-slate-300" x-text="`${selectedCenter.address}, ${selectedCenter.city}`"></p>
                            <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-800/40">
                                <span class="text-slate-500 dark:text-slate-400">Center Hours:</span>
                                <strong class="ml-1 text-slate-900 dark:text-white" x-text="selectedCenter.hours"></strong>
                            </div>
                        </div>
                    </x-ui.card>
                </template>
            </div>
        </div>
    </div>
</x-app-layout>

