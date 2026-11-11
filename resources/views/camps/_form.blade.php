@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $campRecord = $camp ?? [];
    $campStatus = $campRecord['status'] ?? 'Draft';
    $existingRegistrationsCount = (int) ($campRecord['registeredDonors'] ?? 0);

    $defaultWalkIn = array_key_exists('allowWalkIn', $campRecord)
        ? ($campRecord['allowWalkIn'] ? 'Yes' : 'No')
        : 'Yes';

    $initialForm = [
        'name' => old('name', $campRecord['name'] ?? ''),
        'description' => old('description', $campRecord['description'] ?? ''),
        'organizerName' => old('organizer_name', $campRecord['organizerName'] ?? ''),
        'organizerContact' => old('organizer_contact', $campRecord['organizerContact'] ?? ''),
        'organizerEmail' => old('organizer_email', $campRecord['organizerEmail'] ?? ''),
        'venueName' => old('venue_name', $campRecord['venueName'] ?? ''),
        'fullAddress' => old('full_address', $campRecord['fullAddress'] ?? ''),
        'city' => old('city', $campRecord['city'] ?? ''),
        'locationNotes' => old('location_notes', $campRecord['locationNotes'] ?? ''),
        'startDate' => old('start_date', $campRecord['startDate'] ?? ''),
        'endDate' => old('end_date', $campRecord['endDate'] ?? ''),
        'startTime' => old('start_time', $campRecord['startTime'] ?? ''),
        'endTime' => old('end_time', $campRecord['endTime'] ?? ''),
        'capacity' => old('capacity', $campRecord['capacity'] ?? ''),
        'registrationDeadline' => old('registration_deadline', $campRecord['registrationDeadline'] ?? ''),
        'allowWalkIn' => old('allow_walk_in', $defaultWalkIn),
        'donorRequirements' => old('donor_requirements', $campRecord['donorRequirements'] ?? ''),
        'additionalNotes' => old('additional_notes', $campRecord['additionalNotes'] ?? ''),
    ];

    $cancelUrl = $isEdit && isset($campRecord['id'])
        ? route('camps.show', $campRecord['id'])
        : route('camps.index');
@endphp

<div
    x-data="campForm({
        mode: @js($isEdit ? 'edit' : 'create'),
        campId: @js($campRecord['id'] ?? null),
        campStatus: @js($campStatus),
        existingRegistrationsCount: @js($existingRegistrationsCount),
        form: @js($initialForm)
    })"
    class="space-y-6"
>
    @if ($isEdit && in_array($campStatus, ['Completed', 'Cancelled'], true))
        <x-ui.alert variant="warning" :title="$campStatus . ' Camp Modification Notice'">
            This donation camp is currently marked <strong>{{ $campStatus }}</strong>. Editing schedule or capacity parameters in preview mode requires administrative verification, and all existing donor registration history ({{ count($campRecord['registrations'] ?? []) }} record(s)) remains preserved.
        </x-ui.alert>
    @endif

    @if ($isEdit && $existingRegistrationsCount > 0)
        <x-ui.alert variant="info" title="Preserved Donor Registrations Guard">
            <strong>{{ $existingRegistrationsCount }} active donor registration(s)</strong> are already booked for this camp and remain preserved. Maximum donor capacity cannot be reduced below {{ $existingRegistrationsCount }}.
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

    <form @submit.prevent="submitForm()" novalidate class="space-y-6">
        {{-- Section 1: Camp Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        1. Camp Information
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Drive title, operational overview, and coordinator contact details.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-ui.input
                        id="camp-name"
                        name="name"
                        label="Camp Name"
                        placeholder="e.g. Central Plaza Community Blood Drive"
                        icon="calendar"
                        :value="$initialForm['name']"
                        required
                        x-model="form.name"
                        @blur="validateField('name')"
                        x-bind:class="errors.name ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.name"
                        x-cloak
                        x-text="errors.name"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="camp-description"
                        name="description"
                        label="Description"
                        rows="3"
                        placeholder="Describe phlebotomy setup, target blood groups, and drive scope..."
                        :value="$initialForm['description']"
                        required
                        x-model="form.description"
                        @blur="validateField('description')"
                        x-bind:class="errors.description ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.description"
                        x-cloak
                        x-text="errors.description"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-organizer-name"
                        name="organizer_name"
                        label="Organizer Name"
                        placeholder="e.g. Elena Martinez"
                        icon="user"
                        :value="$initialForm['organizerName']"
                        required
                        x-model="form.organizerName"
                        @blur="validateField('organizerName')"
                        x-bind:class="errors.organizerName ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.organizerName"
                        x-cloak
                        x-text="errors.organizerName"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-organizer-contact"
                        name="organizer_contact"
                        type="tel"
                        label="Organizer Contact Number"
                        placeholder="+1 (555) 210-8840"
                        icon="phone"
                        :value="$initialForm['organizerContact']"
                        required
                        x-model="form.organizerContact"
                        @blur="validateField('organizerContact')"
                        x-bind:class="errors.organizerContact ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.organizerContact"
                        x-cloak
                        x-text="errors.organizerContact"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.input
                        id="camp-organizer-email"
                        name="organizer_email"
                        type="email"
                        label="Organizer Email"
                        placeholder="coordinator@organization.org"
                        icon="envelope"
                        :value="$initialForm['organizerEmail']"
                        required
                        x-model="form.organizerEmail"
                        @blur="validateField('organizerEmail')"
                        x-bind:class="errors.organizerEmail ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.organizerEmail"
                        x-cloak
                        x-text="errors.organizerEmail"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 2: Location Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        2. Location Information
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Venue identification, street address, city/district, and access instructions.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="camp-venue-name"
                        name="venue_name"
                        label="Venue Name"
                        placeholder="e.g. Central Medical Plaza Pavilion"
                        icon="building-hospital"
                        :value="$initialForm['venueName']"
                        required
                        x-model="form.venueName"
                        @blur="validateField('venueName')"
                        x-bind:class="errors.venueName ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.venueName"
                        x-cloak
                        x-text="errors.venueName"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-city"
                        name="city"
                        label="City"
                        placeholder="e.g. Metro Center"
                        icon="map-pin"
                        :value="$initialForm['city']"
                        required
                        x-model="form.city"
                        @blur="validateField('city')"
                        x-bind:class="errors.city ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.city"
                        x-cloak
                        x-text="errors.city"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.input
                        id="camp-full-address"
                        name="full_address"
                        label="Full Address"
                        placeholder="Street number, concourse, wing or hall"
                        icon="map-pin"
                        :value="$initialForm['fullAddress']"
                        required
                        x-model="form.fullAddress"
                        @blur="validateField('fullAddress')"
                        x-bind:class="errors.fullAddress ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.fullAddress"
                        x-cloak
                        x-text="errors.fullAddress"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="camp-location-notes"
                        name="location_notes"
                        label="Additional Location Notes"
                        rows="2"
                        placeholder="Parking validation, accessibility ramp, security desk check-in..."
                        hint="Optional donor wayfinding notes."
                        :value="$initialForm['locationNotes']"
                        x-model="form.locationNotes"
                    />
                </div>
            </div>
        </x-ui.card>

        {{-- Section 3: Schedule --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        3. Schedule
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Operating dates and phlebotomy hours. End date must be on or after start date.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <x-ui.input
                        id="camp-start-date"
                        name="start_date"
                        type="date"
                        label="Start Date"
                        :value="$initialForm['startDate']"
                        required
                        x-model="form.startDate"
                        @change="validateField('startDate'); validateField('endDate'); validateField('registrationDeadline')"
                        x-bind:class="errors.startDate ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.startDate"
                        x-cloak
                        x-text="errors.startDate"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-end-date"
                        name="end_date"
                        type="date"
                        label="End Date"
                        :value="$initialForm['endDate']"
                        required
                        x-model="form.endDate"
                        @change="validateField('endDate'); validateField('endTime')"
                        x-bind:class="errors.endDate ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.endDate"
                        x-cloak
                        x-text="errors.endDate"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-start-time"
                        name="start_time"
                        type="time"
                        label="Start Time"
                        :value="$initialForm['startTime']"
                        required
                        x-model="form.startTime"
                        @change="validateField('startTime'); validateField('endTime')"
                        x-bind:class="errors.startTime ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.startTime"
                        x-cloak
                        x-text="errors.startTime"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-end-time"
                        name="end_time"
                        type="time"
                        label="End Time"
                        :value="$initialForm['endTime']"
                        required
                        x-model="form.endTime"
                        @change="validateField('endTime')"
                        x-bind:class="errors.endTime ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.endTime"
                        x-cloak
                        x-text="errors.endTime"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 4: Capacity & Registration --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        4. Capacity &amp; Registration
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Maximum donor slots, pre-registration cutoff date, and ongoing walk-in policy.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="camp-capacity"
                        name="capacity"
                        type="number"
                        min="{{ $isEdit ? max(1, $existingRegistrationsCount) : 1 }}"
                        step="1"
                        label="Maximum Donor Capacity"
                        placeholder="e.g. 50"
                        icon="users"
                        hint="{{ $isEdit && $existingRegistrationsCount > 0 ? 'Must be at least ' . $existingRegistrationsCount . ' (existing active registrations).' : 'Positive integer representing total donor booking slots.' }}"
                        :value="$initialForm['capacity']"
                        required
                        x-model="form.capacity"
                        @blur="validateField('capacity')"
                        x-bind:class="errors.capacity ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.capacity"
                        x-cloak
                        x-text="errors.capacity"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="camp-registration-deadline"
                        name="registration_deadline"
                        type="date"
                        label="Registration Deadline"
                        hint="Must be on or before the camp start date."
                        :value="$initialForm['registrationDeadline']"
                        required
                        x-model="form.registrationDeadline"
                        @change="validateField('registrationDeadline')"
                        x-bind:class="errors.registrationDeadline ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.registrationDeadline"
                        x-cloak
                        x-text="errors.registrationDeadline"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                        Allow Walk-in Donors <span class="text-crimson-600 dark:text-crimson-400">*</span>
                    </span>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Determines whether ongoing camps permit same-day donor registration while available slots remain.
                    </p>

                    <div class="mt-2.5 flex flex-wrap gap-3">
                        <label
                            for="walkin-yes"
                            :class="form.allowWalkIn === 'Yes'
                                ? 'border-crimson-500 bg-crimson-50/70 text-crimson-900 ring-2 ring-crimson-500/20 dark:border-crimson-400 dark:bg-crimson-950/50 dark:text-crimson-100'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800'"
                            class="inline-flex items-center gap-2.5 rounded-xl border px-4 py-2.5 text-sm font-medium transition cursor-pointer"
                        >
                            <input
                                id="walkin-yes"
                                type="radio"
                                name="allow_walk_in"
                                value="Yes"
                                x-model="form.allowWalkIn"
                                @change="validateField('allowWalkIn')"
                                class="size-4 border-slate-300 text-crimson-600 focus:ring-crimson-500"
                            />
                            <span>Yes &mdash; Permit walk-ins when ongoing</span>
                        </label>

                        <label
                            for="walkin-no"
                            :class="form.allowWalkIn === 'No'
                                ? 'border-crimson-500 bg-crimson-50/70 text-crimson-900 ring-2 ring-crimson-500/20 dark:border-crimson-400 dark:bg-crimson-950/50 dark:text-crimson-100'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800'"
                            class="inline-flex items-center gap-2.5 rounded-xl border px-4 py-2.5 text-sm font-medium transition cursor-pointer"
                        >
                            <input
                                id="walkin-no"
                                type="radio"
                                name="allow_walk_in"
                                value="No"
                                x-model="form.allowWalkIn"
                                @change="validateField('allowWalkIn')"
                                class="size-4 border-slate-300 text-crimson-600 focus:ring-crimson-500"
                            />
                            <span>No &mdash; Pre-registered donors only</span>
                        </label>
                    </div>
                    <p
                        x-show="errors.allowWalkIn"
                        x-cloak
                        x-text="errors.allowWalkIn"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 5: Additional Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        5. Additional Information
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Eligibility prerequisites for donors and coordinator logistics notes.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5">
                <div>
                    <x-ui.textarea
                        id="camp-donor-requirements"
                        name="donor_requirements"
                        label="Requirements for Donors"
                        rows="3"
                        placeholder="e.g. Photo ID, minimum 50 kg weight, light meal and hydration prior to arrival..."
                        :value="$initialForm['donorRequirements']"
                        required
                        x-model="form.donorRequirements"
                        @blur="validateField('donorRequirements')"
                        x-bind:class="errors.donorRequirements ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.donorRequirements"
                        x-cloak
                        x-text="errors.donorRequirements"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.textarea
                        id="camp-additional-notes"
                        name="additional_notes"
                        label="Additional Notes"
                        rows="3"
                        placeholder="Optional mobile van parking, cold-chain courier schedules, or outreach tags..."
                        hint="Optional coordination notes."
                        :value="$initialForm['additionalNotes']"
                        x-model="form.additionalNotes"
                    />
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <button
                        type="button"
                        @click="resetForm()"
                        class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                    >
                        Reset Fields
                    </button>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                        <x-ui.button variant="outline" size="md" :href="$cancelUrl">
                            Cancel
                        </x-ui.button>

                        <x-ui.button variant="primary" size="md" type="submit">
                            <x-icons.icon name="check" class="size-4" />
                            <span>{{ $isEdit ? 'Save Camp Changes' : 'Create Donation Camp' }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>

