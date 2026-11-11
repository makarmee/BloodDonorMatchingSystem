@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $requestRecord = $bloodRequest ?? [];

    $initialForm = [
        'hospitalName' => old('hospital_name', $requestRecord['hospitalName'] ?? ''),
        'contactPerson' => old('contact_person', $requestRecord['contactPerson'] ?? ''),
        'contactNumber' => old('contact_number', $requestRecord['contactNumber'] ?? ''),
        'hospitalAddress' => old('hospital_address', $requestRecord['hospitalAddress'] ?? ''),
        'email' => old('email', $requestRecord['email'] ?? ''),
        'bloodGroup' => old('blood_group', $requestRecord['bloodGroup'] ?? ''),
        'requestedUnits' => old('requested_units', $requestRecord['requestedUnits'] ?? ''),
        'requiredDate' => old('required_date', $requestRecord['requiredDate'] ?? ''),
        'priority' => old('priority', $requestRecord['priority'] ?? ''),
        'requestReason' => old('request_reason', $requestRecord['requestReason'] ?? ''),
        'additionalNotes' => old('additional_notes', $requestRecord['additionalNotes'] ?? ''),
    ];

    $bloodGroupOptions = collect($bloodGroups)
        ->mapWithKeys(fn (string $group): array => [$group => $group])
        ->all();

    $priorityOptions = collect($priorities)
        ->mapWithKeys(fn (string $priority): array => [$priority => $priority])
        ->all();

    $existingFulfilled = (int) ($requestRecord['fulfilledUnits'] ?? 0);

    $cancelUrl = $isEdit && isset($requestRecord['id'])
        ? route('requests.show', $requestRecord['id'])
        : route('requests.index');
@endphp

<div
    x-data="requestForm({
        mode: @js($isEdit ? 'edit' : 'create'),
        requestId: @js($requestRecord['id'] ?? null),
        existingFulfilledUnits: @js($existingFulfilled),
        form: @js($initialForm)
    })"
    class="space-y-6"
>
    @if ($isEdit && $existingFulfilled > 0)
        <x-ui.alert variant="info" title="Fulfillment Consistency Guard Active">
            This request already has <strong>{{ $existingFulfilled }} fulfilled units</strong> recorded. Requested units cannot be edited below {{ $existingFulfilled }} to preserve fulfillment consistency.
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
        {{-- Section 1: Hospital Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        Hospital Information
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Requesting medical institution, transfusion contact officer, and delivery address.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <x-ui.input
                        id="request-hospital-name"
                        name="hospital_name"
                        label="Hospital Name"
                        placeholder="e.g. City General Hospital"
                        icon="building-hospital"
                        :value="$initialForm['hospitalName']"
                        required
                        x-model="form.hospitalName"
                        @blur="validateField('hospitalName')"
                        x-bind:class="errors.hospitalName ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.hospitalName"
                        x-cloak
                        x-text="errors.hospitalName"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-contact-person"
                        name="contact_person"
                        label="Contact Person"
                        placeholder="e.g. Dr. Elena Morales"
                        icon="user"
                        :value="$initialForm['contactPerson']"
                        required
                        x-model="form.contactPerson"
                        @blur="validateField('contactPerson')"
                        x-bind:class="errors.contactPerson ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.contactPerson"
                        x-cloak
                        x-text="errors.contactPerson"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-contact-number"
                        name="contact_number"
                        type="tel"
                        label="Contact Number"
                        placeholder="+1 (555) 289-4100"
                        icon="phone"
                        :value="$initialForm['contactNumber']"
                        required
                        x-model="form.contactNumber"
                        @blur="validateField('contactNumber')"
                        x-bind:class="errors.contactNumber ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.contactNumber"
                        x-cloak
                        x-text="errors.contactNumber"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-email"
                        name="email"
                        type="email"
                        label="Email"
                        placeholder="transfusion@hospital.org"
                        icon="envelope"
                        :value="$initialForm['email']"
                        required
                        x-model="form.email"
                        @blur="validateField('email')"
                        x-bind:class="errors.email ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.email"
                        x-cloak
                        x-text="errors.email"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-hospital-address"
                        name="hospital_address"
                        label="Hospital Address"
                        placeholder="Street address, ward/wing, district"
                        icon="map-pin"
                        :value="$initialForm['hospitalAddress']"
                        required
                        x-model="form.hospitalAddress"
                        @blur="validateField('hospitalAddress')"
                        x-bind:class="errors.hospitalAddress ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.hospitalAddress"
                        x-cloak
                        x-text="errors.hospitalAddress"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 2: Request Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        Request Information
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        Specify blood group, required quantity, clinical urgency priority, and fulfillment deadline.
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.select
                        id="request-blood-group"
                        name="blood_group"
                        label="Blood Group"
                        placeholder="Select blood group"
                        :options="$bloodGroupOptions"
                        :value="$initialForm['bloodGroup']"
                        required
                        x-model="form.bloodGroup"
                        @change="validateField('bloodGroup')"
                        x-bind:class="errors.bloodGroup ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.bloodGroup"
                        x-cloak
                        x-text="errors.bloodGroup"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-units"
                        name="requested_units"
                        type="number"
                        label="Number of Units Required"
                        placeholder="e.g. 4"
                        hint="{{ $isEdit && $existingFulfilled > 0 ? 'Minimum allowed: ' . $existingFulfilled . ' (already fulfilled).' : 'Enter a positive integer quantity.' }}"
                        icon="droplet"
                        :min="max(1, $existingFulfilled)"
                        step="1"
                        :value="$initialForm['requestedUnits']"
                        required
                        x-model="form.requestedUnits"
                        @blur="validateField('requestedUnits')"
                        x-bind:class="errors.requestedUnits ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.requestedUnits"
                        x-cloak
                        x-text="errors.requestedUnits"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="request-required-date"
                        name="required_date"
                        type="date"
                        label="Required Date"
                        icon="calendar"
                        :value="$initialForm['requiredDate']"
                        required
                        x-model="form.requiredDate"
                        @change="validateField('requiredDate')"
                        x-bind:class="errors.requiredDate ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.requiredDate"
                        x-cloak
                        x-text="errors.requiredDate"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.select
                        id="request-priority"
                        name="priority"
                        label="Priority"
                        placeholder="Select priority"
                        :options="$priorityOptions"
                        :value="$initialForm['priority']"
                        required
                        x-model="form.priority"
                        @change="validateField('priority')"
                        x-bind:class="errors.priority ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.priority"
                        x-cloak
                        x-text="errors.priority"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="request-reason"
                        name="request_reason"
                        label="Request Reason"
                        rows="3"
                        placeholder="e.g. Emergency trauma transfusion, scheduled cardiothoracic surgery, or oncology support..."
                        :value="$initialForm['requestReason']"
                        required
                        x-model="form.requestReason"
                        @blur="validateField('requestReason')"
                        x-bind:class="errors.requestReason ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.requestReason"
                        x-cloak
                        x-text="errors.requestReason"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="request-additional-notes"
                        name="additional_notes"
                        label="Additional Notes"
                        rows="3"
                        placeholder="Courier dispatch instructions, irradiation/CMV preferences, or ward delivery dock details..."
                        hint="Optional logistics notes (up to 1000 characters)."
                        :value="$initialForm['additionalNotes']"
                        x-model="form.additionalNotes"
                        @blur="validateField('additionalNotes')"
                        x-bind:class="errors.additionalNotes ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.additionalNotes"
                        x-cloak
                        x-text="errors.additionalNotes"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
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
                            <span>{{ $isEdit ? 'Save Request Changes' : 'Submit Blood Request' }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>

