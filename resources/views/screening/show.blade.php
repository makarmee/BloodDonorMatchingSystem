@php
    $recordsList = collect($records ?? [$sample]);
    $overallStatus = $sample['overallScreeningStatus'] ?? $sample['screeningResult'] ?? 'Pending';

    $resultVariant = match ($overallStatus) {
        'Passed' => 'success',
        'Failed' => 'danger',
        default => 'warning',
    };

    $collectionVariant = match ($sample['collectionStatus']) {
        'Completed' => 'success',
        'Scheduled' => 'info',
        default => 'danger',
    };

    $passedCount = $recordsList->where('screeningResult', 'Passed')->count();
    $pendingCount = $recordsList->where('screeningResult', 'Pending')->count();
    $failedCount = $recordsList->where('screeningResult', 'Failed')->count();
    $totalCount = $recordsList->count();

    $pendingRecord = $recordsList->firstWhere('screeningResult', 'Pending');
    $availableMethods = $assayMethods ?? \App\Models\ScreeningRecord::ASSAY_METHODS;
    $initialMethodCandidate = $pendingRecord['assayMethod'] ?? ($sample['assayMethod'] ?? '');
    $initialAssayMethod = old(
        'assay_method',
        in_array($initialMethodCandidate, $availableMethods, true)
            ? $initialMethodCandidate
            : \App\Models\ScreeningRecord::DEFAULT_ASSAY_METHOD
    );
    $initialRecordId = old('screening_record_id', $pendingRecord['recordId'] ?? ($screeningRecord?->id ?? ''));
    $initialTestTypeId = old('test_type_id', $pendingRecord['testTypeId'] ?? ($screeningRecord?->test_type_id ?? ''));
    $initialTestTypeName = __($pendingRecord['testType'] ?? ($sample['testType'] ?? ''));
    $initialScreeningCode = $pendingRecord['id'] ?? ($sample['id'] ?? $sample['donationId']);
@endphp

<x-app-layout :title="__('Lab Screening') . ' · ' . $sample['donationId']" :page-title="__('Screening Details')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('screening.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Lab Screening') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="font-mono text-slate-700 dark:text-slate-200 dir-ltr">{{ $sample['donationId'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-900 dark:text-white dir-ltr">
                        {{ $sample['donationId'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="md" />
                    <x-ui.badge :variant="$resultVariant" :dot="true">
                        {{ __('Screening Status') }}: {{ __($overallStatus) }}
                    </x-ui.badge>
                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                        {{ __($sample['collectionStatus']) }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Donor') }}: {{ __($sample['donorName']) }} (<span class="font-mono dir-ltr">{{ $sample['donorId'] }}</span>) &middot; {{ __($sample['donationType']) }} &middot; {{ $sample['quantityMl'] }} {{ __('ml') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('screening.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Screening') }}</span>
                </x-ui.button>

                @if ($pendingRecord && isset($donationModel) && $donationModel)
                    <x-ui.button
                        variant="primary"
                        size="md"
                        type="button"
                        @click="$dispatch('open-record-modal', {
                            recordId: @js($pendingRecord['recordId'] ?? ''),
                            testTypeId: @js($pendingRecord['testTypeId'] ?? ''),
                            testTypeName: @js(__($pendingRecord['testType'] ?? '')),
                            screeningCode: @js($pendingRecord['id'] ?? $sample['donationId']),
                            assayMethod: @js(in_array($pendingRecord['assayMethod'] ?? '', $availableMethods, true) ? $pendingRecord['assayMethod'] : \App\Models\ScreeningRecord::DEFAULT_ASSAY_METHOD)
                        })"
                    >
                        <x-icons.icon name="beaker" class="size-4" />
                        <span>{{ __('Record Result') }}</span>
                    </x-ui.button>
                @endif

                @if (isset($donationModel) && $donationModel)
                    @can('releaseToInventory', $donationModel)
                        @if (isset($readiness) && $readiness['is_ready_for_release'])
                            <x-ui.button
                                variant="primary"
                                size="md"
                                type="button"
                                @click="$dispatch('open-modal', 'release-inventory-modal')"
                            >
                                <x-icons.icon name="check-badge" class="size-4" />
                                <span>{{ __('Release to Inventory') }}</span>
                            </x-ui.button>
                        @endif
                    @endcan
                @endif

                <x-ui.button variant="outline" size="md" :href="route('screening.create', ['donation' => $sample['donationId']])">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>{{ __('Record Screening Test') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div
        class="space-y-6"
        x-data="{
            activeRecordId: @js($initialRecordId),
            activeTestTypeId: @js($initialTestTypeId),
            activeTestTypeName: @js($initialTestTypeName),
            activeScreeningCode: @js($initialScreeningCode),
            activeAssayMethod: @js($initialAssayMethod),
            openRecordModal(detail) {
                this.activeRecordId = detail.recordId ?? '';
                this.activeTestTypeId = detail.testTypeId ?? '';
                this.activeTestTypeName = detail.testTypeName ?? '';
                this.activeScreeningCode = detail.screeningCode ?? '';
                this.activeAssayMethod = detail.assayMethod ?? @js(\App\Models\ScreeningRecord::DEFAULT_ASSAY_METHOD);
                this.$dispatch('open-modal', 'record-result-modal');
            }
        }"
        @open-record-modal.window="openRecordModal($event.detail)"
    >
        @if (session('success'))
            <x-ui.alert variant="success" :title="session('success')" />
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger" :title="session('error')" />
        @endif

        @if (isset($readiness) && $readiness)
            @if ($readiness['is_ready_for_release'])
                <x-ui.alert variant="success" :title="__('Inventory Release Eligible')">
                    {{ __('All mandatory laboratory screening assays are completed and passed. This donation\'s unit is eligible for release to active inventory.') }}
                </x-ui.alert>
            @elseif (! empty($readiness['blocking_reasons']))
                <x-ui.alert variant="warning" :title="__('Inventory Release Blocked')">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($readiness['blocking_reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif
        @else
            {{-- Clinical Preview Notice --}}
            <x-ui.alert variant="info" :title="__('Sample Screening Record Notice')">
                {{ __('These laboratory screening figures are frontend preview fixtures only and do not represent real clinical clearance or automated medical authorization.') }}
            </x-ui.alert>
        @endif

        {{-- Summary Strip --}}
        <section aria-label="Screening summary metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Screening Panel') }}
                </p>
                <p class="mt-3 text-lg font-bold text-slate-900 dark:text-white">
                    {{ $totalCount }} {{ __('Tests') }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Passed') }}: {{ $passedCount }} &middot; {{ __('Pending') }}: {{ $pendingCount }} &middot; {{ __('Failed') }}: {{ $failedCount }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Screening Status') }}
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$resultVariant" :dot="true">
                        {{ __($overallStatus) }}
                    </x-ui.badge>
                    <x-icons.icon name="shield-check" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Overall donation screening gate status') }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Test Date') }}
                </p>
                <p class="mt-3 text-lg font-bold text-slate-900 dark:text-white">
                    {{ __($sample['testDateFormatted']) }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Collected') }}: {{ $sample['collectionDateFormatted'] ?: __('Not set') }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Blood Group') }}
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="lg" />
                    <span class="font-mono text-xs font-medium text-slate-500 dark:text-slate-400 dir-ltr">
                        {{ $sample['primaryUnitCode'] ?? $sample['donorId'] }}
                    </span>
                </div>
                @if (! empty($sample['primaryUnitStatus']))
                    <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Primary Unit') }}: {{ __($sample['primaryUnitStatus']) }}
                    </p>
                @endif
            </x-ui.card>
        </section>

        {{-- Main Details Grid --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left Column: Donor Information & Linked Donation --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Donor Information') }}
                        </h2>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donor ID') }}</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white dir-ltr text-start">{{ $sample['donorId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donor Name') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ __($sample['donorName']) }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Blood Group') }}</dt>
                            <dd>
                                <x-bdms.blood-group-badge :group="$sample['bloodGroup']" size="sm" />
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Phone') }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $sample['donorPhone'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Email') }}</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $sample['donorEmail'] }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donors.show', $sample['donorId'])" class="w-full justify-center">
                            <x-icons.icon name="user" class="size-4" />
                            <span>{{ __('View Donor Profile') }}</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __('Linked Donation Details') }}
                            </h2>
                            <x-ui.badge :variant="$collectionVariant" :dot="true">
                                {{ __($sample['collectionStatus']) }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donation ID') }}</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white dir-ltr text-start">{{ $sample['donationId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donation Type') }}</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ __($sample['donationType']) }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Collected Volume') }}</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ $sample['quantityMl'] }} {{ __('ml') }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Collection Date') }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $sample['collectionDateFormatted'] }}</dd>
                        </div>

                        @if (! empty($sample['primaryUnitCode']) && isset($donationModel) && $donationModel->primaryBloodUnit)
                            <div class="flex items-center justify-between gap-4 py-2.5">
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Primary Unit') }}</dt>
                                <dd class="font-mono text-xs font-semibold text-crimson-600 dark:text-crimson-400 dir-ltr text-start">
                                    <a href="{{ route('inventory.units.show', $donationModel->primaryBloodUnit->id) }}" class="hover:underline">
                                        {{ $sample['primaryUnitCode'] }}
                                    </a>
                                </dd>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Location') }}</dt>
                            <dd class="text-end text-slate-700 dark:text-slate-200">{{ __($sample['collectionLocation']) }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donations.show', $sample['donationId'])" class="w-full justify-center">
                            <x-icons.icon name="heart-pulse" class="size-4" />
                            <span>{{ __('View Donation Record') }}</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </div>

            {{-- Right Column: All Screening Panel Records & Audit Timestamps --}}
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    {{ __('Screening Panel & Test Results') }}
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('All mandatory and supplemental laboratory tests recorded for this donation.') }}
                                </p>
                            </div>

                            <x-ui.badge :variant="$resultVariant" :dot="true">
                                {{ __($overallStatus) }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    @if ($recordsList->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-900/50 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Screening ID') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Test Type') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Screening Result') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Assay Method') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Test Date') }}</th>
                                        <th scope="col" class="px-5 py-3 text-end">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                    @foreach ($recordsList as $recordItem)
                                        @php
                                            $itemVariant = match ($recordItem['screeningResult']) {
                                                'Passed' => 'success',
                                                'Failed' => 'danger',
                                                default => 'warning',
                                            };
                                            $itemMethod = in_array($recordItem['assayMethod'] ?? '', $availableMethods, true)
                                                ? $recordItem['assayMethod']
                                                : \App\Models\ScreeningRecord::DEFAULT_ASSAY_METHOD;
                                        @endphp
                                        <tr class="align-top transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                            <td class="px-5 py-3.5 whitespace-nowrap">
                                                <span class="font-mono text-xs font-semibold text-slate-800 dark:text-slate-200 dir-ltr">
                                                    {{ $recordItem['id'] }}
                                                </span>
                                                <p class="mt-1 font-mono text-2xs text-slate-500 dark:text-slate-400 dir-ltr">
                                                    {{ $recordItem['specimenTube'] }}
                                                </p>
                                            </td>

                                            <td class="px-5 py-3.5">
                                                <div class="font-semibold text-slate-900 dark:text-white">
                                                    {{ __($recordItem['testType']) }}
                                                </div>
                                                @if (! empty($recordItem['isConfirmatory']))
                                                    <span class="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                                        {{ __('Confirmatory') }}
                                                    </span>
                                                @endif
                                            </td>

                                            <td class="px-5 py-3.5 whitespace-nowrap">
                                                <x-ui.badge :variant="$itemVariant" :dot="true">
                                                    {{ __($recordItem['screeningResult']) }}
                                                </x-ui.badge>
                                                <p class="mt-1 text-2xs text-slate-500 dark:text-slate-400">
                                                    {{ __($recordItem['labStaff']) }}
                                                </p>
                                            </td>

                                            <td class="px-5 py-3.5">
                                                <p class="text-xs font-medium text-slate-800 dark:text-slate-200">
                                                    {{ __($recordItem['assayMethod']) }}
                                                </p>
                                                @if (! empty($recordItem['testNotes']))
                                                    <p class="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                                                        {{ __($recordItem['testNotes']) }}
                                                    </p>
                                                @endif
                                            </td>

                                            <td class="px-5 py-3.5 whitespace-nowrap text-xs text-slate-700 dark:text-slate-300">
                                                <div class="font-medium">{{ __($recordItem['testDateFormatted']) }}</div>
                                                <div class="mt-1 text-2xs text-slate-500 dark:text-slate-400">
                                                    {{ __($recordItem['resultRecordedAt']) }}
                                                </div>
                                            </td>

                                            <td class="px-5 py-3.5 text-end whitespace-nowrap">
                                                @if ($recordItem['screeningResult'] === 'Pending' && isset($donationModel) && $donationModel)
                                                    <x-ui.button
                                                        variant="outline"
                                                        size="sm"
                                                        type="button"
                                                        @click="openRecordModal({
                                                            recordId: @js($recordItem['recordId'] ?? ''),
                                                            testTypeId: @js($recordItem['testTypeId'] ?? ''),
                                                            testTypeName: @js(__($recordItem['testType'])),
                                                            screeningCode: @js($recordItem['id']),
                                                            assayMethod: @js($itemMethod)
                                                        })"
                                                    >
                                                        <x-icons.icon name="beaker" class="size-3.5" />
                                                        <span>{{ __('Record Result') }}</span>
                                                    </x-ui.button>
                                                @else
                                                    <span class="text-xs text-slate-400 dark:text-slate-500">&mdash;</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-6 py-10 text-center">
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ __('No laboratory screening records have been logged for this donation yet.') }}
                            </p>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Audit & Processing Timestamps') }}
                        </h2>
                    </x-slot:header>

                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Sample Collected') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['collectedAt'] }}</dd>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Logged in Lab Queue') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $sample['loggedAt'] }}</dd>
                        </div>

                        <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Result Recorded') }}</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ __($sample['resultRecordedAt']) }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>

        @if (isset($donationModel) && $donationModel)
            {{-- Release to Inventory Modal --}}
            <x-ui.modal name="release-inventory-modal" :title="__('Release Quarantined Unit to Inventory')" max-width="md">
                <form method="POST" action="{{ route('screening.donations.release', $donationModel) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {!! __('Confirm release of donation :code and its tested blood unit to available hospital inventory.', ['code' => '<strong class="dir-ltr">' . $donationModel->donation_code . '</strong>']) !!}
                    </p>

                    <div>
                        <label for="release-storage" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Storage Location') }}
                        </label>
                        <input
                            type="text"
                            id="release-storage"
                            name="storage_location"
                            placeholder="{{ __('e.g. Cold Room 2, Rack A-4') }}"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                    </div>

                    <div>
                        <label for="release-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Screening / Release Notes') }}
                        </label>
                        <textarea
                            id="release-notes"
                            name="screening_notes"
                            rows="3"
                            placeholder="{{ __('Mandatory panel non-reactive; unit cleared for clinical distribution...') }}"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'release-inventory-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700 cursor-pointer"
                        >
                            {{ __('Confirm Inventory Release') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            @if ($pendingRecord)
                {{-- Record Assay Result Modal --}}
                <x-ui.modal name="record-result-modal" :title="__('Record Laboratory Assay Result')" max-width="md">
                    <form method="POST" action="{{ route('screening.records.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="donation_id" value="{{ $donationModel->donation_code }}" />
                        <input type="hidden" name="screening_record_id" value="{{ $initialRecordId }}" x-model="activeRecordId" />
                        <input type="hidden" name="test_type_id" value="{{ $initialTestTypeId }}" x-model="activeTestTypeId" />

                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            <span>{{ __('Test Type') }}: <strong x-text="activeTestTypeName">{{ $initialTestTypeName }}</strong></span>
                            &middot;
                            <span>{{ __('Screening ID') }}: <strong class="font-mono dir-ltr" x-text="activeScreeningCode">{{ $initialScreeningCode }}</strong></span>
                        </p>

                        <div>
                            <label for="modal-screening-result" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Assay Result') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            <select
                                id="modal-screening-result"
                                name="screening_result"
                                required
                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="">{{ __('Select Result...') }}</option>
                                <option value="Passed">{{ __('Passed (Non-Reactive / Negative)') }}</option>
                                <option value="Failed">{{ __('Failed (Reactive / Positive)') }}</option>
                            </select>
                        </div>

                        <div>
                            <label for="modal-assay-method" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Assay Method') }}
                            </label>
                            <select
                                id="modal-assay-method"
                                name="assay_method"
                                x-model="activeAssayMethod"
                                required
                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                @foreach ($availableMethods as $method)
                                    <option value="{{ $method }}" @selected($initialAssayMethod === $method)>
                                        {{ __($method) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="modal-test-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Test Observations & Notes') }}
                            </label>
                            <textarea
                                id="modal-test-notes"
                                name="test_notes"
                                rows="3"
                                placeholder="{{ __('Optical density, batch analyzer ID, controls verified...') }}"
                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            ></textarea>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button
                                type="button"
                                @click="$dispatch('close-modal', 'record-result-modal')"
                                class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                            >
                                {{ __('Cancel') }}
                            </button>
                            <button
                                type="submit"
                                class="rounded-xl bg-crimson-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700 cursor-pointer"
                            >
                                {{ __('Save Result') }}
                            </button>
                        </div>
                    </form>
                </x-ui.modal>
            @endif
        @endif
    </div>
</x-app-layout>
