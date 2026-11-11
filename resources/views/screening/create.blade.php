@php
    $donationOptions = collect($donations)
        ->mapWithKeys(fn (array $item): array => [
            $item['id'] => "{$item['id']} — {$item['donorName']} ({$item['bloodGroup']} · {$item['donationType']})",
        ])
        ->all();

    $testTypeOptions = collect($testTypes)
        ->mapWithKeys(fn (string $type): array => [$type => $type])
        ->all();

    $screeningResultOptions = [
        'Pending' => 'Pending',
        'Passed' => 'Passed',
        'Failed' => 'Failed',
    ];
@endphp

<x-app-layout title="Record Screening Test" page-title="Record Screening" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('screening.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Lab Screening
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Record Screening</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Record Lab Screening Test
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Link an existing sample donation, select the diagnostic assay panel, and record explicit laboratory findings.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Screening</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div
        class="mx-auto max-w-4xl space-y-6"
        x-data="screeningForm({ donations: @js($donations) })"
    >
        {{-- Explicit Screening Policy Notice --}}
        <x-ui.alert variant="warning" title="Manual Laboratory Verification Required">
            Screening results are never automatically marked <strong>Passed</strong>. Select an explicit status (<strong>Pending</strong>, <strong>Passed</strong>, or <strong>Failed</strong>). Sample preview submissions do not represent real clinical clearance.
        </x-ui.alert>

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
            {{-- Section 1: Donation Selection & Auto Donor Summary --}}
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Donation &amp; Donor Selection
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Choose an existing donation record to automatically display the associated donor and ABO/Rh blood group.
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
                                label="Search Sample Donation"
                                placeholder="Type Donation ID (e.g. DON-4405), donor name, or blood group..."
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
                                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                    >
                                        <div>
                                            <span class="font-mono text-xs font-bold text-slate-900 dark:text-white" x-text="item.id"></span>
                                            <span class="mx-1.5 text-slate-400">&middot;</span>
                                            <span class="font-medium text-slate-800 dark:text-slate-200" x-text="item.donorName"></span>
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
                                    No sample donations match your search.
                                </p>
                            </div>
                        </div>

                        <div>
                            <x-ui.select
                                id="screening-donation-id"
                                name="donation_id"
                                label="Select Donation"
                                placeholder="Select existing donation"
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
                                Associated Donor
                            </p>

                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Donor Name</p>
                                    <p
                                        class="mt-0.5 text-sm font-bold text-slate-900 dark:text-white"
                                        x-text="selectedDonation ? selectedDonation.donorName : 'Not selected'"
                                    ></p>
                                </div>

                                <div class="text-right">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Blood Group</p>
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
                                    Donor ID:
                                    <span class="font-mono font-semibold text-slate-800 dark:text-slate-200" x-text="selectedDonation?.donorId"></span>
                                </p>
                                <p class="text-slate-500 dark:text-slate-400">
                                    Collected:
                                    <span class="font-medium text-slate-800 dark:text-slate-200" x-text="`${selectedDonation?.collectionDateFormatted} · ${selectedDonation?.donationType} (${selectedDonation?.quantityMl} ml)`"></span>
                                </p>
                            </div>
                        </div>

                        <p x-show="selectedDonation === null" class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            Donor name, Donor ID, and blood group populate automatically when a donation is selected.
                        </p>
                    </div>
                </div>
            </x-ui.card>

            {{-- Section 2: Laboratory Test Details --}}
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Laboratory Test &amp; Screening Outcome
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Specify the assay panel, test date, explicit outcome, laboratory staff member, and observations.
                        </p>
                    </div>
                </x-slot:header>

                <div class="space-y-5">
                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div>
                            <x-ui.select
                                id="screening-test-type"
                                name="test_type"
                                label="Test Type"
                                placeholder="Select test type"
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
                                label="Test Date"
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
                                label="Screening Result"
                                placeholder="Select screening result"
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
                                label="Laboratory Staff"
                                placeholder="e.g. Dr. Priya Nair (Serology)"
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
                    </div>

                    <div>
                        <x-ui.textarea
                            id="screening-test-notes"
                            name="test_notes"
                            label="Test Notes"
                            rows="4"
                            placeholder="Record analyzer batch ID, optical density cutoff observations, confirmatory notes, or quarantine actions..."
                            hint="Optional assay remarks (up to 1000 characters)."
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
                            Reset Fields
                        </button>

                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
                            <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                                Cancel
                            </x-ui.button>

                            <x-ui.button variant="primary" size="md" type="submit">
                                <x-icons.icon name="check" class="size-4" />
                                <span>Save Screening Entry</span>
                            </x-ui.button>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        </form>
    </div>
</x-app-layout>

