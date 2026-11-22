@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $donorRecord = $donor ?? [];

    $initialForm = [
        'name' => old('name', $donorRecord['name'] ?? ''),
        'email' => old('email', $donorRecord['email'] ?? ''),
        'phone' => old('phone', $donorRecord['phone'] ?? ''),
        'gender' => old('gender', $donorRecord['gender'] ?? ''),
        'dateOfBirth' => old('date_of_birth', $donorRecord['dateOfBirth'] ?? ''),
        'bloodGroup' => old('blood_group', $donorRecord['bloodGroup'] ?? ''),
        'address' => old('address', $donorRecord['address'] ?? ''),
        'emergencyContact' => old('emergency_contact', $donorRecord['emergencyContact'] ?? ''),
        'medicalNotes' => old('medical_notes', $donorRecord['medicalNotes'] ?? ''),
        'availabilityStatus' => old('availability_status', $donorRecord['availabilityStatus'] ?? 'Available'),
    ];

    $genderOptions = [
        'Female' => __('Female'),
        'Male' => __('Male'),
        'Other' => __('Other'),
    ];

    $bloodGroupOptions = collect($bloodGroups)
        ->mapWithKeys(fn (string $group): array => [$group => $group])
        ->all();

    $availabilityOptions = [
        'Available' => __('Available'),
        'Temporarily Unavailable' => __('Temporarily Unavailable'),
        'Inactive' => __('Inactive'),
    ];

    $cancelUrl = $isEdit && isset($donorRecord['id'])
        ? route('donors.show', $donorRecord['id'])
        : route('donors.index');
@endphp

<div
    x-data="donorForm({
        mode: @js($isEdit ? 'edit' : 'create'),
        donorId: @js($donorRecord['id'] ?? null),
        form: @js($initialForm)
    })"
    class="space-y-6"
>
    @if (session('success'))
        <x-ui.alert variant="success" :title="session('success')" class="mb-4" />
    @endif

    @if ($errors->any())
        <x-ui.alert variant="danger" :title="__('Validation errors occurred')" class="mb-4">
            <ul class="list-disc ps-5 text-xs space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
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
            aria-label="{{ __('Dismiss notification') }}"
            class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
        >
            <x-icons.icon name="x-mark" class="size-4" />
        </button>
    </div>

    <form
        method="POST"
        action="{{ $isEdit ? route('donors.update', $donorRecord['id']) : route('donors.store') }}"
        @submit="if (!validateAll()) { $event.preventDefault(); feedback = { type: 'danger', title: window.__ ? window.__('Validation errors found') : 'Validation errors found', message: window.__ ? window.__('Please complete or correct the highlighted donor fields before continuing.') : 'Please complete or correct the highlighted donor fields before continuing.' }; window.scrollTo({ top: 0, behavior: 'smooth' }); }"
        novalidate
        class="space-y-6"
    >
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif
        {{-- Section 1: Personal Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Personal Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Core identity details and confirmed ABO/Rh blood group classification.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="donor-name"
                        name="name"
                        :label="__('Full Name')"
                        placeholder="e.g. Elena Rostova"
                        icon="user"
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

                <div>
                    <x-ui.select
                        id="donor-blood-group"
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
                        id="donor-gender"
                        name="gender"
                        :label="__('Gender')"
                        :placeholder="__('Select gender')"
                        :options="$genderOptions"
                        :value="$initialForm['gender']"
                        required
                        x-model="form.gender"
                        @change="validateField('gender')"
                        x-bind:class="errors.gender ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.gender"
                        x-cloak
                        x-text="errors.gender"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="donor-dob"
                        name="date_of_birth"
                        type="date"
                        :label="__('Date of Birth')"
                        icon="calendar"
                        :value="$initialForm['dateOfBirth']"
                        required
                        x-model="form.dateOfBirth"
                        @change="validateField('dateOfBirth')"
                        x-bind:class="errors.dateOfBirth ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.dateOfBirth"
                        x-cloak
                        x-text="errors.dateOfBirth"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 2: Contact & Emergency Details --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Contact & Emergency Details') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Reachable donor contact channels, residential address, and next-of-kin emergency contact.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="donor-email"
                        name="email"
                        type="email"
                        :label="__('Email')"
                        placeholder="donor@example.org"
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
                        id="donor-phone"
                        name="phone"
                        type="tel"
                        :label="__('Phone Number')"
                        placeholder="+1 (555) 234-8910"
                        icon="phone"
                        :value="$initialForm['phone']"
                        required
                        x-model="form.phone"
                        @blur="validateField('phone')"
                        x-bind:class="errors.phone ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.phone"
                        x-cloak
                        x-text="errors.phone"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.input
                        id="donor-emergency-contact"
                        name="emergency_contact"
                        :label="__('Emergency Contact')"
                        placeholder="e.g. Viktor Rostov (Spouse) - +1 (555) 234-8999"
                        icon="shield-check"
                        :value="$initialForm['emergencyContact']"
                        required
                        x-model="form.emergencyContact"
                        @blur="validateField('emergencyContact')"
                        x-bind:class="errors.emergencyContact ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.emergencyContact"
                        x-cloak
                        x-text="errors.emergencyContact"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="donor-address"
                        name="address"
                        :label="__('Address')"
                        rows="2"
                        :placeholder="__('Street address, apartment or suite, city, state/province, postal code')"
                        :value="$initialForm['address']"
                        required
                        x-model="form.address"
                        @blur="validateField('address')"
                        x-bind:class="errors.address ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.address"
                        x-cloak
                        x-text="errors.address"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 3: Medical Notes & Availability --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Medical Notes & Availability') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Donation scheduling readiness and relevant clinical intake notes.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <x-ui.select
                            id="donor-availability-status"
                            name="availability_status"
                            :label="__('Availability Status')"
                            :options="$availabilityOptions"
                            :value="$initialForm['availabilityStatus']"
                            required
                            x-model="form.availabilityStatus"
                            @change="validateField('availabilityStatus')"
                            x-bind:class="errors.availabilityStatus ? 'ring-2 ring-crimson-500/25' : ''"
                        />
                        <p
                            x-show="errors.availabilityStatus"
                            x-cloak
                            x-text="errors.availabilityStatus"
                            class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                        ></p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 dark:border-slate-800 dark:bg-slate-800/50">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                {{ __('Clinical Eligibility Status') }}
                            </span>

                            @if ($isEdit && isset($donorRecord['eligibilityStatus']))
                                @php
                                    $statusVariant = match ($donorRecord['eligibilityStatus']) {
                                        'Eligible' => 'success',
                                        'Not Eligible' => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <x-ui.badge :variant="$statusVariant" :dot="true">
                                    {{ __($donorRecord['eligibilityStatus']) }}
                                </x-ui.badge>
                            @else
                                <x-ui.badge variant="warning" :dot="true">
                                    {{ __('Pending Review') }}
                                </x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-2 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                            {{ __('Eligibility is determined exclusively by verified clinical screening criteria and backend rules rather than a frontend timer.') }}
                        </p>
                    </div>
                </div>

                <div>
                    <x-ui.textarea
                        id="donor-medical-notes"
                        name="medical_notes"
                        :label="__('Medical Notes')"
                        rows="4"
                        :placeholder="__('Record verified allergies, hemoglobin screening notes, deferral remarks, or appointment preferences...')"
                        :hint="__('Optional clinical context for intake staff (up to 1000 characters).')"
                        :value="$initialForm['medicalNotes']"
                        x-model="form.medicalNotes"
                        @blur="validateField('medicalNotes')"
                        x-bind:class="errors.medicalNotes ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.medicalNotes"
                        x-cloak
                        x-text="errors.medicalNotes"
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
                            <span>{{ $isEdit ? __('Save Changes') : __('Register Donor') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>

