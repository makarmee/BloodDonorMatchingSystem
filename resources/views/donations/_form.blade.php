@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $donationRecord = $donation ?? [];

    $initialForm = [
        'donorId' => old('donor_id', $donationRecord['donorId'] ?? ''),
        'donationDate' => old('donation_date', $donationRecord['donationDate'] ?? ''),
        'donationType' => old('donation_type', $donationRecord['donationType'] ?? ''),
        'quantityMl' => old('quantity_ml', $donationRecord['quantityMl'] ?? ''),
        'collectionLocation' => old('collection_location', $donationRecord['collectionLocation'] ?? ''),
        'staffId' => (string) old('staff_id', $donationRecord['staffId'] ?? ''),
        'legacyStaffMember' => $donationRecord['staffMember'] ?? '',
        'screeningStatus' => old('screening_status', $donationRecord['screeningStatus'] ?? ''),
        'screeningNotes' => old('screening_notes', $donationRecord['screeningNotes'] ?? ''),
        'collectionStatus' => old('collection_status', $donationRecord['collectionStatus'] ?? ''),
        'collectionNotes' => old('collection_notes', $donationRecord['collectionNotes'] ?? ''),
    ];

    $staffOptions = collect($staffMembers ?? [])
        ->mapWithKeys(function ($member): array {
            $label = "{$member->full_name} ({$member->employee_id})";
            if (! $member->isActive()) {
                $label .= ' — ' . __('Inactive');
            }

            return [(string) $member->id => $label];
        })
        ->all();

    $hasLegacyStaffAttribution = $isEdit && $initialForm['staffId'] === '' && $initialForm['legacyStaffMember'] !== '';

    $donorOptions = collect($donors)
        ->mapWithKeys(fn (array $item): array => [
            $item['id'] => "{$item['id']} — {$item['name']} ({$item['bloodGroup']})",
        ])
        ->all();

    $donationTypeOptions = [
        'Whole Blood' => __('Whole Blood'),
        'Plasma' => __('Plasma'),
        'Platelets' => __('Platelets'),
    ];

    $screeningStatusOptions = [
        'Pending' => __('Pending'),
        'Passed' => __('Passed'),
        'Failed' => __('Failed'),
    ];

    $collectionStatusOptions = [
        'Scheduled' => __('Scheduled'),
        'Completed' => __('Completed'),
        'Cancelled' => __('Cancelled'),
    ];

    $cancelUrl = $isEdit && isset($donationRecord['id'])
        ? route('donations.show', $donationRecord['id'])
        : route('donations.index');
@endphp

<div
    x-data="donationForm({
        mode: @js($isEdit ? 'edit' : 'create'),
        donationId: @js($donationRecord['id'] ?? null),
        hasLegacyStaff: @js($hasLegacyStaffAttribution),
        donors: @js($donors),
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
        action="{{ $isEdit ? route('donations.update', $donationRecord['id']) : route('donations.store') }}"
        @submit="if (!validateAll()) { $event.preventDefault(); feedback = { type: 'danger', title: window.__ ? window.__('Validation errors found') : 'Validation errors found', message: window.__ ? window.__('Please complete or correct the highlighted donation fields before continuing.') : 'Please complete or correct the highlighted donation fields before continuing.' }; window.scrollTo({ top: 0, behavior: 'smooth' }); }"
        novalidate
        class="space-y-6"
    >
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif
        {{-- Section 1: Donor Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Donor Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Search and select a registered sample donor to populate their confirmed Donor ID and Blood Group.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                <div class="space-y-3 lg:col-span-2">
                    {{-- Quick Search Donor Combobox --}}
                    <div class="relative" @click.outside="donorDropdownOpen = false">
                        <x-ui.input
                            id="donation-donor-search"
                            name="donor_search"
                            type="search"
                            :label="__('Search Registered Donor')"
                            :placeholder="__('Type donor name, ID (e.g. DNR-1041), or blood group...')"
                            icon="magnifying-glass"
                            x-model="donorSearch"
                            @focus="donorDropdownOpen = true"
                            @input="donorDropdownOpen = true"
                        />

                        <div
                            x-show="donorDropdownOpen && donorSearch.trim() !== ''"
                            x-cloak
                            class="absolute z-20 mt-1.5 max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                        >
                            <template x-for="donorItem in filteredDonors" :key="donorItem.id">
                                <button
                                    type="button"
                                    @click="selectDonor(donorItem.id)"
                                    class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                >
                                    <div>
                                        <span class="font-semibold text-slate-900 dark:text-white" x-text="donorItem.name"></span>
                                        <span class="ms-2 font-mono text-xs text-slate-500 dark:text-slate-400 dir-ltr" x-text="donorItem.id"></span>
                                    </div>
                                    <span
                                        class="inline-flex items-center rounded-md bg-crimson-50 px-2 py-0.5 text-xs font-bold text-crimson-700 ring-1 ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800"
                                        x-text="donorItem.bloodGroup"
                                    ></span>
                                </button>
                            </template>

                            <p
                                x-show="filteredDonors.length === 0"
                                class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400"
                            >
                                {{ __('No sample donors match your search.') }}
                            </p>
                        </div>
                    </div>

                    {{-- Authoritative Donor Select --}}
                    <div>
                        <x-ui.select
                            id="donation-donor-id"
                            name="donor_id"
                            :label="__('Selected Donor')"
                            :placeholder="__('Select registered donor')"
                            :options="$donorOptions"
                            :value="$initialForm['donorId']"
                            required
                            x-model="form.donorId"
                            @change="validateField('donorId')"
                            x-bind:class="errors.donorId ? 'ring-2 ring-crimson-500/25' : ''"
                        />
                        <p
                            x-show="errors.donorId"
                            x-cloak
                            x-text="errors.donorId"
                            class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                        ></p>
                    </div>
                </div>

                {{-- Automatic Selected Donor ID & Blood Group Preview --}}
                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                    <div>
                        <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                            {{ __('Donor Identity Summary') }}
                        </p>

                        <div class="mt-3 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Donor ID') }}</p>
                                <p
                                    class="mt-0.5 font-mono text-sm font-bold text-slate-900 dark:text-white dir-ltr text-start"
                                    x-text="selectedDonor ? selectedDonor.id : 'Not selected'"
                                ></p>
                            </div>

                            <div class="text-end">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Blood Group') }}</p>
                                <span
                                    class="mt-0.5 inline-flex items-center rounded-md px-2.5 py-0.5 text-sm font-bold ring-1 ring-inset"
                                    :class="selectedDonor
                                        ? 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800'
                                        : 'bg-slate-100 text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700'"
                                    x-text="selectedDonor ? selectedDonor.bloodGroup : '—'"
                                ></span>
                            </div>
                        </div>

                        <div x-show="selectedDonor !== null" x-cloak class="mt-3 border-t border-slate-200 pt-3 text-xs dark:border-slate-700">
                            <p class="font-semibold text-slate-800 dark:text-slate-200" x-text="selectedDonor?.name"></p>
                            <p class="mt-0.5 text-slate-500 dark:text-slate-400">
                                {{ __('Registry Eligibility:') }}
                                <span class="font-medium text-slate-700 dark:text-slate-200" x-text="selectedDonor?.eligibilityStatus"></span>
                            </p>
                        </div>
                    </div>

                    <p x-show="selectedDonor === null" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Donor ID and ABO/Rh blood group appear automatically once a donor is selected.') }}
                    </p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 2: Donation Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Donation Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Specify collection date, blood component type, unit volume (ml), facility, and attending staff member.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="donation-date"
                        name="donation_date"
                        type="date"
                        :label="__('Donation Date')"
                        icon="calendar"
                        :value="$initialForm['donationDate']"
                        required
                        x-model="form.donationDate"
                        @change="validateField('donationDate')"
                        x-bind:class="errors.donationDate ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.donationDate"
                        x-cloak
                        x-text="errors.donationDate"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.select
                        id="donation-type"
                        name="donation_type"
                        :label="__('Donation Type')"
                        :placeholder="__('Select donation type')"
                        :options="$donationTypeOptions"
                        :value="$initialForm['donationType']"
                        required
                        x-model="form.donationType"
                        @change="validateField('donationType')"
                        x-bind:class="errors.donationType ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.donationType"
                        x-cloak
                        x-text="errors.donationType"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="donation-quantity"
                        name="quantity_ml"
                        type="number"
                        :label="__('Quantity (ml)')"
                        placeholder="e.g. 450"
                        :hint="__('Typical range: Platelets 250 ml · Whole Blood 450 ml · Plasma 550–650 ml.')"
                        icon="droplet"
                        min="150"
                        max="800"
                        step="10"
                        :value="$initialForm['quantityMl']"
                        required
                        x-model="form.quantityMl"
                        @blur="validateField('quantityMl')"
                        x-bind:class="errors.quantityMl ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.quantityMl"
                        x-cloak
                        x-text="errors.quantityMl"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.input
                        id="donation-location"
                        name="collection_location"
                        :label="__('Collection Location')"
                        placeholder="e.g. Central Medical Plaza"
                        icon="map-pin"
                        list="donation-location-options"
                        :value="$initialForm['collectionLocation']"
                        required
                        x-model="form.collectionLocation"
                        @blur="validateField('collectionLocation')"
                        x-bind:class="errors.collectionLocation ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <datalist id="donation-location-options">
                        @foreach ($locations as $location)
                            <option value="{{ $location }}"></option>
                        @endforeach
                    </datalist>
                    <p
                        x-show="errors.collectionLocation"
                        x-cloak
                        x-text="errors.collectionLocation"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div class="md:col-span-2">
                    <x-ui.select
                        id="donation-staff"
                        name="staff_id"
                        :label="__('Staff Member')"
                        :placeholder="__('Select active staff member')"
                        :options="$staffOptions"
                        :value="$initialForm['staffId']"
                        :required="! $hasLegacyStaffAttribution"
                        x-model="form.staffId"
                        @change="validateField('staffId')"
                        x-bind:class="errors.staffId ? 'ring-2 ring-crimson-500/25' : ''"
                    />

                    @if ($hasLegacyStaffAttribution)
                        <input
                            type="hidden"
                            name="staff_member"
                            value="{{ $initialForm['legacyStaffMember'] }}"
                            :disabled="form.staffId !== ''"
                        />
                        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Historical attribution:') }} <span class="font-medium text-slate-700 dark:text-slate-200">{{ $initialForm['legacyStaffMember'] }}</span>
                        </p>
                    @endif

                    @if ($staffOptions === [] && ! $hasLegacyStaffAttribution)
                        <p class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                            {{ __('No active staff members are currently available in the Staff Directory.') }}
                        </p>
                    @endif

                    <p
                        x-show="errors.staffId"
                        x-cloak
                        x-text="errors.staffId"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 3: Screening Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Screening Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Explicit laboratory screening status and pre/post-collection clinical remarks.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <x-ui.select
                            id="donation-screening-status"
                            name="screening_status"
                            :label="__('Screening Status')"
                            :placeholder="__('Select screening status')"
                            :options="$screeningStatusOptions"
                            :value="$initialForm['screeningStatus']"
                            required
                            x-model="form.screeningStatus"
                            @change="validateField('screeningStatus')"
                            x-bind:class="errors.screeningStatus ? 'ring-2 ring-crimson-500/25' : ''"
                        />
                        <p
                            x-show="errors.screeningStatus"
                            x-cloak
                            x-text="errors.screeningStatus"
                            class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                        ></p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 dark:border-slate-800 dark:bg-slate-800/50">
                        <p class="text-xs font-semibold tracking-wide text-slate-600 uppercase dark:text-slate-300">
                            {{ __('No Auto-Approval Rule') }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                            {{ __('Screening status must always be explicitly selected from verified laboratory results. Entries are never marked as medically approved automatically.') }}
                        </p>
                    </div>
                </div>

                <div>
                    <x-ui.textarea
                        id="donation-screening-notes"
                        name="screening_notes"
                        :label="__('Screening Notes')"
                        rows="3"
                        :placeholder="__('Record hemoglobin reading, serology/NAT observations, or temporary deferral reasons...')"
                        :hint="__('Optional laboratory and clinical screening notes (up to 1000 characters).')"
                        :value="$initialForm['screeningNotes']"
                        x-model="form.screeningNotes"
                        @blur="validateField('screeningNotes')"
                        x-bind:class="errors.screeningNotes ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.screeningNotes"
                        x-cloak
                        x-text="errors.screeningNotes"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>
            </div>
        </x-ui.card>

        {{-- Section 4: Collection Information --}}
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Collection Information') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Phlebotomy session progress, bag/chain-of-custody notes, and scheduling outcome.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="space-y-5">
                <div class="md:w-1/2">
                    <x-ui.select
                        id="donation-collection-status"
                        name="collection_status"
                        :label="__('Collection Status')"
                        :placeholder="__('Select collection status')"
                        :options="$collectionStatusOptions"
                        :value="$initialForm['collectionStatus']"
                        required
                        x-model="form.collectionStatus"
                        @change="validateField('collectionStatus')"
                        x-bind:class="errors.collectionStatus ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.collectionStatus"
                        x-cloak
                        x-text="errors.collectionStatus"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
                </div>

                <div>
                    <x-ui.textarea
                        id="donation-collection-notes"
                        name="collection_notes"
                        :label="__('Collection Notes')"
                        rows="3"
                        :placeholder="__('Record venipuncture duration, cold-chain seal reference, or cancellation remarks...')"
                        :hint="__('Optional phlebotomy and cold-chain transfer notes (up to 1000 characters).')"
                        :value="$initialForm['collectionNotes']"
                        x-model="form.collectionNotes"
                        @blur="validateField('collectionNotes')"
                        x-bind:class="errors.collectionNotes ? 'ring-2 ring-crimson-500/25' : ''"
                    />
                    <p
                        x-show="errors.collectionNotes"
                        x-cloak
                        x-text="errors.collectionNotes"
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
                            <span>{{ $isEdit ? __('Save Changes') : __('Record Donation') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>

