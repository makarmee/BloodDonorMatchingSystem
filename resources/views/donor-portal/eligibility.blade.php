@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $statusVariant = match ($eligibility['status']) {
        'Eligible' => 'success',
        'Not Eligible' => 'danger',
        'Pending Review' => 'warning',
        default => 'neutral',
    };
@endphp

<x-app-layout title="Eligibility Information — Donor Portal" page-title="Eligibility Information" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Donation Eligibility Information
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Synchronized administrative status and clinical review summary for {{ $donorProfile['name'] }}.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('donor-portal.dashboard', $donorQuery)">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Overview</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="space-y-6" x-data="donorEligibility(@js(['eligibility' => $eligibility]))">
        {{-- Medical Staff Confirmation Disclaimer Banner (Rule 6 & Rule 8) --}}
        <x-ui.alert variant="info" title="Medical Clearance Notice" :dismissible="false">
            This portal displays your latest recorded administrative and screening status only. Final donation eligibility must be confirmed on-site by authorized medical staff prior to collection. Donors cannot self-approve or modify eligibility records.
        </x-ui.alert>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Primary Eligibility Status Card --}}
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Current Eligibility Status
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    Displayed strictly from verified BDMS sample data without automatic countdown calculation.
                                </p>
                            </div>

                            <x-ui.badge :variant="$statusVariant" :dot="true">
                                {{ $eligibility['status'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-5">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Current Eligibility Status
                                </p>
                                <p class="mt-1.5 text-lg font-bold text-slate-900 dark:text-white" x-text="eligibility.status">
                                    {{ $eligibility['status'] }}
                                </p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Last Reviewed Date
                                </p>
                                <p class="mt-1.5 text-lg font-bold text-slate-900 dark:text-white">
                                    {{ $eligibility['lastReviewedDate'] ?? 'Unavailable' }}
                                </p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-4 dark:border-slate-800 dark:bg-slate-800/40">
                                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                    Next Eligible Date
                                </p>
                                <p class="mt-1.5 text-base font-bold text-slate-900 dark:text-white">
                                    {{ $eligibility['nextEligibleDate'] ?? 'Pending clinical assessment' }}
                                </p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                            <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                                Reason for Current Status
                            </p>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                                {{ $eligibility['reason'] }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/60 p-4 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/30 dark:text-slate-300">
                            <p class="font-semibold text-slate-900 dark:text-white">
                                Authorized Staff Verification Required
                            </p>
                            <p class="mt-1">
                                {{ $eligibility['medicalConfirmationRequired'] }}
                            </p>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            {{-- Administrative Information vs Medical Clearance Card (Rule 6) --}}
            <div>
                <x-ui.card>
                    <x-slot:header>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Administrative Record vs. Medical Clearance
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Administrative facts are informational only and do not imply clinical clearance.
                            </p>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4">
                        <dl class="divide-y divide-slate-200 text-xs dark:divide-slate-800">
                            @foreach ($eligibility['administrativeFacts'] as $fact)
                                <div class="flex items-center justify-between gap-3 py-2.5">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ $fact['label'] }}</dt>
                                    <dd class="text-right font-semibold text-slate-900 dark:text-white">{{ $fact['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3.5 text-xs text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/60 dark:text-amber-200">
                            <p class="font-semibold">Status Legend</p>
                            <ul class="mt-1.5 space-y-1 opacity-90">
                                <li><strong>Eligible:</strong> Prior sample screening recorded ready; requires day-of check.</li>
                                <li><strong>Not Eligible:</strong> Active clinical deferral logged in donor record.</li>
                                <li><strong>Pending Review:</strong> Screening or questionnaire under staff evaluation.</li>
                                <li><strong>Unknown:</strong> Eligibility information unavailable; staff confirmation needed.</li>
                            </ul>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>

