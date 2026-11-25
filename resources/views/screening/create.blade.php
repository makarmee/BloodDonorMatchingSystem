@php
    $donationOptions = collect($donations)
        ->mapWithKeys(fn (array $item): array => [
            $item['id'] => "{$item['id']} — " . __($item['donorName']) . " ({$item['bloodGroup']} · " . __($item['donationType']) . ")",
        ])
        ->all();

    $testTypeOptions = collect($testTypes)
        ->mapWithKeys(fn (string $type): array => [$type => __($type)])
        ->all();

    $screeningResultOptions = [
        'Pending' => __('Pending'),
        'Passed' => __('Passed'),
        'Failed' => __('Failed'),
    ];

    $availableAssayMethods = $assayMethods ?? \App\Models\ScreeningRecord::ASSAY_METHODS;
    $assayMethodOptions = collect($availableAssayMethods)
        ->mapWithKeys(fn (string $method): array => [$method => __($method)])
        ->all();
    $persistedMode = (bool) ($hasPersistedDonations ?? false);
@endphp

<x-app-layout :title="__('Record Screening Test')" :page-title="__('Record Screening')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('screening.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Lab Screening') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Record Screening') }}</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Record Lab Screening Test') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Link an existing sample donation, select the diagnostic assay panel, and record explicit laboratory findings.') }}
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Screening') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div
        class="mx-auto max-w-4xl space-y-6"
        x-data="screeningForm({
            donations: @js($donations),
            hasPersistedDonations: @js($persistedMode),
            form: {
                donationId: @js(old('donation_id', request()->query('donation', ''))),
                testType: @js(old('test_type', '')),
                testDate: @js(old('test_date', $persistedMode ? now()->toDateString() : '')),
                screeningResult: @js(old('screening_result', '')),
                assayMethod: @js(old('assay_method', $defaultAssayMethod ?? \App\Models\ScreeningRecord::DEFAULT_ASSAY_METHOD)),
                isConfirmatory: @js((bool) old('is_confirmatory', false)),
                labStaff: @js(old('lab_staff', $persistedMode ? (auth()->user()?->name ?? '') : '')),
                testNotes: @js(old('test_notes', ''))
            }
        })"
    >
        {{-- Explicit Screening Policy Notice --}}
        @if ($persistedMode)
            <x-ui.alert variant="warning" :title="__('Manual Laboratory Verification Required')">
                {!! __('Screening results are never automatically marked :passed. Select an explicit status (:pending, :passed, or :failed). Recorded results are persisted to the laboratory screening log immediately.', [
                    'passed' => '<strong>' . __('Passed') . '</strong>',
                    'pending' => '<strong>' . __('Pending') . '</strong>',
                    'failed' => '<strong>' . __('Failed') . '</strong>'
                ]) !!}
            </x-ui.alert>
        @else
            <x-ui.alert variant="warning" :title="__('Manual Laboratory Verification Required')">
                {!! __('Screening results are never automatically marked :passed. Select an explicit status (:pending, :passed, or :failed). Sample preview submissions do not represent real clinical clearance.', [
                    'passed' => '<strong>' . __('Passed') . '</strong>',
                    'pending' => '<strong>' . __('Pending') . '</strong>',
                    'failed' => '<strong>' . __('Failed') . '</strong>'
                ]) !!}
            </x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" :title="__('Unable to Save Screening Record')">
                <ul class="list-disc list-inside space-y-1 text-sm">
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
                aria-label="{{ __('Dismiss message') }}"
                class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        <form method="POST" action="{{ route('screening.records.store') }}" @submit="handleSubmit($event)" novalidate class="space-y-6">
            @csrf
            {{-- Section 1: Donation Selection & Auto Donor Summary --}}
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Donation & Donor Selection') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Choose an existing donation record to automatically display the associated donor and ABO/Rh blood group.') }}
                        </p>
                    </div>
                </x-slot:header>

                <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div class="space-y-3 lg:col-span-2">
                        <div class="relative" @click.outside="donationDropdownOpen = false">
                            <x-ui.input
                                id="screening-donation-search"
                                name="donation_search"
                                type="search"
                                :label="__('Search Sample Donation')"
                                :placeholder="__('Type Donation ID (e.g. DON-4405), donor name, or blood group...')"
                                icon="magnifying-glass"
                                x-model="donationSearch"
                                @focus="donationDropdownOpen = true"
                                @input="donationDropdownOpen = true"
                            />

                            <div
                                x-show="donationDropdownOpen && donationSearch.trim() !== ''"
                                x-cloak
                                class="absolute z-20 mt-1.5 max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                            >
                                <template x-for="item in filteredDonations" :key="item.id">
                                    <button
                                        type="button"
                                        @click="selectDonation(item.id)"
                                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                    >
                                        <div>
                                            <span class="font-mono text-xs font-bold text-slate-900 dark:text-white dir-ltr text-start" x-text="item.id"></span>
                                            <span class="mx-1.5 text-slate-400">&middot;</span>
                                            <span class="font-medium text-slate-800 dark:text-slate-200" x-text="window.__ ? window.__(item.donorName) : item.donorName"></span>
                                        </div>
                                        <span
                                            class="inline-flex items-center rounded-md bg-crimson-50 px-2 py-0.5 text-xs font-bold text-crimson-700 ring-1 ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800"
                                            x-text="item.bloodGroup"
                                        ></span>
                                    </button>
                                </template>

                                <p
                                    x-show="filteredDonations.length === 0"
                                    class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400"
                                >
                                    {{ __('No sample donations match your search.') }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <x-ui.select
                                id="screening-donation-id"
                                name="donation_id"
                                :label="__('Select Donation')"
                                :placeholder="__('Select existing donation')"
                                :options="$donationOptions"
                                required
                                x-model="form.donationId"
                                @change="validateField('donationId')"
                                x-bind:class="errors.donationId ? 'ring-2 ring-crimson-500/25' : ''"
                            />
                            <p
                                x-show="errors.donationId"
                                x-cloak
                                x-text="errors.donationId"
                                class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                            ></p>
                        </div>
                    </div>

                    {{-- Auto-displayed Donor & Blood Group Card --}}
                    <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                        <div>
                            <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                {{ __('Associated Donor') }}
                            </p>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Donor Name') }}</p>
                                    <p
                                        class="mt-0.5 text-sm font-bold text-slate-900 dark:text-white"
                                        x-text="selectedDonation ? (window.__ ? window.__(selectedDonation.donorName) : selectedDonation.donorName) : '{{ __('Not selected') }}'"
                                    ></p>
                                </div>

                                <div class="text-end">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Blood Group') }}</p>
                                    <span
                                        class="mt-0.5 inline-flex items-center rounded-md px-2.5 py-0.5 text-sm font-bold ring-1 ring-inset"
                                        :class="selectedDonation
                                            ? 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/60 dark:text-crimson-300 dark:ring-crimson-800'
                                            : 'bg-slate-100 text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700'"
                                        x-text="selectedDonation ? selectedDonation.bloodGroup : '—'"
                                    ></span>
                                </div>
                            </div>

                            <div x-show="selectedDonation !== null" x-cloak class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-xs dark:border-slate-700">
                                <p class="text-slate-500 dark:text-slate-400">
                                    {{ __('Donor ID') }}:
                                    <span class="font-mono font-semibold text-slate-800 dark:text-slate-200 dir-ltr text-start" x-text="selectedDonation?.donorId"></span>
                                </p>
                                <p class="text-slate-500 dark:text-slate-400">
                                    {{ __('Collected') }}:
                                    <span class="font-medium text-slate-800 dark:text-slate-200" x-text="`${selectedDonation?.collectionDateFormatted} · ${window.__ ? window.__(selectedDonation?.donationType) : selectedDonation?.donationType} (${selectedDonation?.quantityMl} {{ __('ml') }})`"></span>
                                </p>
                            </div>
                        </div>

                        <p x-show="selectedDonation === null" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Donor name, Donor ID, and blood group populate automatically when a donation is selected.') }}
                        </p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Section 2: Laboratory Test Details --}}
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Laboratory Test & Screening Outcome') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Specify the assay panel, test date, explicit outcome, laboratory staff member, and observations.') }}
                        </p>
                    </div>
                </x-slot:header>

                <div class="space-y-5">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <x-ui.select
                                id="screening-test-type"
                                name="test_type"
                                :label="__('Test Type')"
                                :placeholder="__('Select test type')"
                                :options="$testTypeOptions"
                                required
                                x-model="form.testType"
                                @change="validateField('testType')"
                                x-bind:class="errors.testType ? 'ring-2 ring-crimson-500/25' : ''"
                            />
                            <p
                                x-show="errors.testType"
                                x-cloak
                                x-text="errors.testType"
                                class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                            ></p>
                        </div>

                        <div>
                            <x-ui.input
                                id="screening-test-date"
                                name="test_date"
                                type="date"
                                :label="__('Test Date')"
                                icon="calendar"
                                required
                                x-model="form.testDate"
                                @change="validateField('testDate')"
                                x-bind:class="errors.testDate ? 'ring-2 ring-crimson-500/25' : ''"
                            />
                            <p
                                x-show="errors.testDate"
                                x-cloak
                                x-text="errors.testDate"
                                class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                            ></p>
                        </div>

                        <div>
                            <x-ui.select
                                id="screening-result"
                                name="screening_result"
                                :label="__('Screening Result')"
                                :placeholder="__('Select screening result')"
                                :options="$screeningResultOptions"
                                required
                                x-model="form.screeningResult"
                                @change="validateField('screeningResult')"
                                x-bind:class="errors.screeningResult ? 'ring-2 ring-crimson-500/25' : ''"
                            />
                            <p
                                x-show="errors.screeningResult"
                                x-cloak
                                x-text="errors.screeningResult"
                                class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                            ></p>
                        </div>

                        <div>
                            <x-ui.input
                                id="screening-lab-staff"
                                name="lab_staff"
                                :label="__('Laboratory Staff')"
                                :placeholder="__('e.g. Dr. Priya Nair (Serology)')"
                                icon="user"
                                list="lab-staff-options"
                                required
                                x-model="form.labStaff"
                                @blur="validateField('labStaff')"
                                x-bind:class="errors.labStaff ? 'ring-2 ring-crimson-500/25' : ''"
                            />
                            <datalist id="lab-staff-options">
                                @foreach ($labStaffSuggestions as $staff)
                                    <option value="{{ $staff }}"></option>
                                @endforeach
                            </datalist>
                            <p
                                x-show="errors.labStaff"
                                x-cloak
                                x-text="errors.labStaff"
                                class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                            ></p>
                        </div>

                        <div>
                            <x-ui.select
                                id="screening-assay-method"
                                name="assay_method"
                                :label="__('Assay Method')"
                                :options="$assayMethodOptions"
                                required
                                x-model="form.assayMethod"
                            />
                        </div>

                        <div class="flex items-center pt-2 md:pt-7">
                            <label for="screening-is-confirmatory" class="inline-flex items-center gap-2.5 text-sm font-medium text-slate-700 dark:text-slate-200 cursor-pointer">
                                <input
                                    id="screening-is-confirmatory"
                                    name="is_confirmatory"
                                    type="checkbox"
                                    value="1"
                                    x-model="form.isConfirmatory"
                                    class="size-4 rounded border-slate-300 text-crimson-600 focus:ring-crimson-500 dark:border-slate-700 dark:bg-slate-900"
                                />
                                <span>{{ __('Confirmatory / Repeat Assay') }}</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <x-ui.textarea
                            id="screening-test-notes"
                            name="test_notes"
                            :label="__('Test Notes')"
                            rows="4"
                            :placeholder="__('Record analyzer batch ID, optical density cutoff observations, confirmatory notes, or quarantine actions...')"
                            :hint="__('Optional assay remarks (up to 1000 characters).')"
                            x-model="form.testNotes"
                            @blur="validateField('testNotes')"
                            x-bind:class="errors.testNotes ? 'ring-2 ring-crimson-500/25' : ''"
                        />
                        <p
                            x-show="errors.testNotes"
                            x-cloak
                            x-text="errors.testNotes"
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
                            <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                                {{ __('Cancel') }}
                            </x-ui.button>

                            <x-ui.button variant="primary" size="md" type="submit">
                                <x-icons.icon name="check" class="size-4" />
                                <span>{{ __('Save Screening Entry') }}</span>
                            </x-ui.button>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </form>
    </div>
</x-app-layout>
