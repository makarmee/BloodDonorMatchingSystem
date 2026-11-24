@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $requestRecord = $bloodRequest ?? [];
    $prefillData = $prefill ?? [];
    $userHospital = $hospital ?? null;
    $availableHospitals = $hospitals ?? collect();

    $initialForm = [
        'hospitalId' => old('hospital_id', $requestRecord['hospitalId'] ?? $prefillData['hospitalId'] ?? $userHospital?->id ?? ''),
        'hospitalName' => old('hospital_name', $requestRecord['hospitalName'] ?? $prefillData['hospitalName'] ?? $userHospital?->name ?? ''),
        'contactPerson' => old('contact_person', $requestRecord['contactPerson'] ?? $prefillData['contactPerson'] ?? ''),
        'contactNumber' => old('contact_number', $requestRecord['contactNumber'] ?? $prefillData['contactNumber'] ?? ''),
        'hospitalAddress' => old('hospital_address', $requestRecord['hospitalAddress'] ?? $prefillData['hospitalAddress'] ?? ''),
        'email' => old('email', $requestRecord['email'] ?? $prefillData['email'] ?? ''),
        'bloodGroup' => old('blood_group', $requestRecord['bloodGroup'] ?? ''),
        'bloodComponentId' => old('blood_component_id', $bloodRequestRecord->blood_component_id ?? $requestRecord['bloodComponentId'] ?? ''),
        'requestedUnits' => old('requested_units', $requestRecord['requestedUnits'] ?? ''),
        'requiredDate' => old('required_date', $requestRecord['requiredDate'] ?? ''),
        'priority' => old('priority', $requestRecord['priority'] ?? ''),
        'requestReason' => old('request_reason', $requestRecord['requestReason'] ?? ''),
        'additionalNotes' => old('additional_notes', $requestRecord['additionalNotes'] ?? ''),
    ];

    $bloodGroupOptions = collect($bloodGroups)
        ->mapWithKeys(fn (string $group): array => [$group => $group])
        ->all();

    $componentOptions = collect($components ?? [])
        ->mapWithKeys(fn ($comp): array => [$comp->id => "{$comp->name} ({$comp->code})"])
        ->all();

    $priorityOptions = collect($priorities)
        ->mapWithKeys(fn (string $priority): array => [$priority => __($priority)])
        ->all();

    $existingFulfilled = (int) ($requestRecord['fulfilledUnits'] ?? 0);

    $cancelUrl = $isEdit && isset($requestRecord['id'])
        ? route('requests.show', $requestRecord['id'])
        : route('requests.index');

    $formAction = $isEdit && isset($bloodRequestRecord)
        ? route('requests.update', $bloodRequestRecord->id)
        : route('requests.store');
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
        <x-ui.alert variant="info" :title="__('Fulfillment Consistency Guard Active')">
            {!! __('This request already has :count fulfilled units recorded. Requested units cannot be edited below :min to preserve fulfillment consistency.', [
                'count' => '<strong>' . $existingFulfilled . ' ' . __('fulfilled units') . '</strong>',
                'min' => $existingFulfilled
            ]) !!}
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
            :aria-label="__('Dismiss message')"
            class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
        >
            <x-icons.icon name="x-mark" class="size-4" />
        </button>
    </div>

    <form
        method="POST"
        action="{{ $formAction }}"
        novalidate
        class="space-y-6"
    >
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif
        {{-- Section 1: Hospital Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Hospital Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Requesting medical institution, transfusion contact officer, and delivery address.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                @if (! $isEdit && ! $userHospital && $availableHospitals->isNotEmpty())
                    <div class="md:col-span-2">
                        <label for="select-target-hospital" class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">
                            {{ __('Select Verified Hospital') }} <span class="text-crimson-500">*</span>
                        </label>
                        <select
                            id="select-target-hospital"
                            name="hospital_id"
                            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs focus:border-crimson-500 focus:outline-none focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-white"
                            @change="
                                const opt = $event.target.selectedOptions ? $event.target.selectedOptions[0] : null;
                                if (opt && opt.value) {
                                    form.hospitalName = opt.dataset.name || form.hospitalName;
                                    if (opt.dataset.contact) form.contactPerson = opt.dataset.contact;
                                    if (opt.dataset.phone) form.contactNumber = opt.dataset.phone;
                                    if (opt.dataset.email) form.email = opt.dataset.email;
                                    if (opt.dataset.address) form.hospitalAddress = opt.dataset.address;
                                    errors.hospitalName = '';
                                }
                            "
                        >
                            <option value="">{{ __('Select a verified partner hospital...') }}</option>
                            @foreach ($availableHospitals as $hospOption)
                                <option
                                    value="{{ $hospOption->id }}"
                                    data-name="{{ $hospOption->name }}"
                                    data-contact="{{ $hospOption->primary_contact_name }}"
                                    data-phone="{{ $hospOption->primary_phone }}"
                                    data-email="{{ $hospOption->primary_email }}"
                                    data-address="{{ $hospOption->address }}"
                                    {{ (string) old('hospital_id', $initialForm['hospitalId']) === (string) $hospOption->id ? 'selected' : '' }}
                                >
                                    {{ $hospOption->name }} ({{ $hospOption->hospital_code ?? 'HSP-'.$hospOption->id }})
                                </option>
                            @endforeach
                        </select>
                        @error('hospital_id')
                            <p class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400">{{ $message }}</p>
                        @enderror
                    </div>
                @elseif ($userHospital)
                    <input type="hidden" name="hospital_id" value="{{ $userHospital->id }}">
                @endif

                <div class="md:col-span-2">
                    <x-ui.input
                        id="request-hospital-name"
                        name="hospital_name"
                        :label="__('Hospital Name')"
                        :placeholder="__('e.g. City General Hospital')"
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
                        :label="__('Contact Person')"
                        :placeholder="__('e.g. Dr. Elena Morales')"
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
                        :label="__('Contact Number')"
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
                        :label="__('Email')"
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
                        :label="__('Hospital Address')"
                        :placeholder="__('Street address, ward/wing, district')"
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
                        {{ __('Request Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Specify blood group, required quantity, clinical urgency priority, and fulfillment deadline.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.select
                        id="request-blood-group"
                        name="blood_group"
                        :label="__('Blood Group')"
                        :placeholder="__('Select blood group')"
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
                    <x-ui.select
                        id="request-blood-component"
                        name="blood_component_id"
                        :label="__('Blood Component')"
                        :placeholder="__('Select component (optional)')"
                        :options="$componentOptions"
                        :value="$initialForm['bloodComponentId']"
                        x-model="form.bloodComponentId"
                    />
                    <p class="mt-1 text-2xs text-slate-500 dark:text-slate-400">
                        {{ __('Optional: leave unselected to determine during fulfillment.') }}
                    </p>
                </div>

                <div>
                    <x-ui.input
                        id="request-units"
                        name="requested_units"
                        type="number"
                        :label="__('Number of Units Required')"
                        placeholder="e.g. 4"
                        :hint="$isEdit && $existingFulfilled > 0 ? __('Minimum allowed: :min (already fulfilled).', ['min' => $existingFulfilled]) : __('Enter a positive integer quantity.')"
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
                        :label="__('Required Date')"
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
                        :label="__('Priority')"
                        :placeholder="__('Select priority')"
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
                        x-text="window.__ ? window.__(errors.priority) : errors.priority"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="request-reason"
                        name="request_reason"
                        :label="__('Request Reason')"
                        rows="3"
                        :placeholder="__('e.g. Emergency trauma transfusion, scheduled cardiothoracic surgery, or oncology support...')"
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
                        :label="__('Additional Notes')"
                        rows="3"
                        :placeholder="__('Courier dispatch instructions, irradiation/CMV preferences, or ward delivery dock details...')"
                        :hint="__('Optional logistics notes (up to 1000 characters).')"
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
                        {{ __('Reset Fields') }}
                    </button>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                        <x-ui.button variant="outline" size="md" :href="$cancelUrl">
                            {{ __('Cancel') }}
                        </x-ui.button>

                        <x-ui.button variant="primary" size="md" type="submit">
                            <x-icons.icon name="check" class="size-4" />
                            <span>{{ $isEdit ? __('Save Request Changes') : __('Submit Blood Request') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
