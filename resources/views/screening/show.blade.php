@php
    $resultVariant = match ($sample['screeningResult']) {
        'Passed' => 'success',
        'Failed' => 'danger',
        default => 'warning',
    };

    $collectionVariant = match ($sample['collectionStatus']) {
        'Completed' => 'success',
        'Scheduled' => 'info',
        default => 'danger',
    };
@endphp

<x-app-layout :title="'Lab Screening · ' . $sample['id']" page-title="Screening Details" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('screening.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Lab Screening
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="font-mono text-slate-700 dark:text-slate-200">{{ $sample['id'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $sample['id'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="md" />
                    <x-ui.badge variant="neutral">
                        {{ $sample['testType'] }}
                    </x-ui.badge>
                    <x-ui.badge :variant="$resultVariant" :dot="true">
                        Result: {{ $sample['screeningResult'] }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Specimen <span class="font-mono">{{ $sample['specimenTube'] }}</span> &middot; Linked to Donation <span class="font-mono">{{ $sample['donationId'] }}</span> &middot; Assigned to {{ $sample['labStaff'] }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Screening</span>
                </x-ui.button>

                <x-ui.button variant="primary" size="md" :href="route('screening.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Record Screening Test</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Clinical Preview Notice --}}
        <x-ui.alert variant="info" title="Sample Screening Record Notice">
            These laboratory screening figures are frontend preview fixtures only and do not represent real clinical clearance or automated medical authorization.
        </x-ui.alert>

        {{-- Summary Strip --}}
        <section aria-label="Screening summary metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Test Panel
                </p>
                <p class="mt-3 text-lg font-bold text-slate-900 dark:text-white">
                    {{ $sample['testType'] }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Specimen: {{ $sample['specimenTube'] }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Screening Result
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$resultVariant" :dot="true">
                        {{ $sample['screeningResult'] }}
                    </x-ui.badge>
                    <x-icons.icon name="shield-check" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Explicitly recorded assay status
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Test Date
                </p>
                <p class="mt-3 text-lg font-bold text-slate-900 dark:text-white">
                    {{ $sample['testDateFormatted'] }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Collected: {{ $sample['collectionDateFormatted'] }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Blood Group
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="lg" />
                    <span class="font-mono text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $sample['donorId'] }}
                    </span>
                </div>
            </x-ui.card>
        </section>

        {{-- Main Details Grid --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left Column: Donor Information & Linked Donation --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Donor Information
                        </h2>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">Donor ID</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white">{{ $sample['donorId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Donor Name</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $sample['donorName'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Blood Group</dt>
                            <dd>
                                <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="sm" />
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Phone</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $sample['donorPhone'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200">{{ $sample['donorEmail'] }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donors.show', $sample['donorId'])" class="w-full justify-center">
                            <x-icons.icon name="user" class="size-4" />
                            <span>View Donor Profile</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Linked Donation Details
                            </h2>
                            <x-ui.badge :variant="$collectionVariant" :dot="true">
                                {{ $sample['collectionStatus'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">Donation ID</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white">{{ $sample['donationId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Donation Type</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ $sample['donationType'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Collected Volume</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ $sample['quantityMl'] }} ml</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Collection Date</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $sample['collectionDateFormatted'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">Location</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $sample['collectionLocation'] }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donations.show', $sample['donationId'])" class="w-full justify-center">
                            <x-icons.icon name="heart-pulse" class="size-4" />
                            <span>View Donation Record</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </div>

            {{-- Right Column: Test Details, Notes & Audit Timestamps --}}
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Test Information &amp; Screening Result
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Assay methodology, specimen reference, responsible staff, and laboratory notes.
                                </p>
                            </div>

                            <x-ui.badge :variant="$resultVariant" :dot="true">
                                {{ $sample['screeningResult'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-5 text-sm">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Assay Method</p>
                                <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['assayMethod'] }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Laboratory Staff</p>
                                <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['labStaff'] }}</p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                Test Notes
                            </p>
                            <p class="mt-1.5 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $sample['testNotes'] ?: 'No laboratory remarks recorded.' }}
                            </p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Audit &amp; Processing Timestamps
                        </h2>
                    </x-slot:header>

                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Sample Collected</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['collectedAt'] }}</dd>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Logged in Lab Queue</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['loggedAt'] }}</dd>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Result Recorded</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['resultRecordedAt'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>

