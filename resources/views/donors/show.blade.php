@php
    $eligibilityVariant = match ($donor['eligibilityStatus']) {
        'Eligible' => 'success',
        'Not Eligible' => 'danger',
        default => 'warning',
    };

    $accountVariant = $donor['accountStatus'] === 'Active' ? 'info' : 'neutral';

    $availabilityVariant = match ($donor['availabilityStatus']) {
        'Available' => 'success',
        'Temporarily Unavailable' => 'warning',
        default => 'neutral',
    };

    $initials = collect(explode(' ', $donor['name']))
        ->filter()
        ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
        ->take(2)
        ->implode('');
@endphp

<x-app-layout :title="'Donor Profile · ' . $donor['name']" page-title="Donor Profile" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <span class="inline-flex size-14 shrink-0 items-center justify-center rounded-2xl bg-crimson-50 text-lg font-bold text-crimson-700 ring-1 ring-crimson-200 dark:bg-crimson-500/15 dark:text-crimson-300 dark:ring-crimson-500/30">
                    {{ $initials }}
                </span>

                <div>
                    <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                        <a href="{{ route('donors.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                            Donors
                        </a>
                        <x-icons.icon name="chevron-right" class="size-3.5" />
                        <span class="font-mono text-slate-700 dark:text-slate-200">{{ $donor['id'] }}</span>
                    </div>

                    <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            {{ $donor['name'] }}
                        </h1>
                        <x-bdms.blood-group-badge :group="$donor['bloodGroup']" size="md" />
                        <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                            {{ $donor['eligibilityStatus'] }}
                        </x-ui.badge>
                    </div>

                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        Donor ID <span class="font-mono font-medium text-slate-800 dark:text-slate-200">{{ $donor['id'] }}</span>
                        &middot; {{ $donor['gender'] }}, {{ $donor['age'] }} yrs
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donors.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Donors</span>
                </x-ui.button>

                <x-ui.button variant="primary" size="md" :href="route('donors.edit', $donor['id'])">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>Edit Donor</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Top Profile Metrics --}}
        <section aria-label="Donor summary metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Blood Group
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <x-bdms.blood-group-badge :group="$donor['bloodGroup']" size="lg" />
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        ABO / Rh Verified
                    </span>
                </div>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Eligibility Status
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                        {{ $donor['eligibilityStatus'] }}
                    </x-ui.badge>
                    <x-icons.icon name="shield-check" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Clinical screening &amp; backend rules
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Last Donation Date
                </p>
                <p class="mt-3 text-xl font-bold text-slate-900 dark:text-white">
                    {{ $donor['lastDonationDate'] ?? 'No donations yet' }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Verified collection timestamp
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Total Donations
                </p>
                <div class="mt-3 flex items-baseline gap-2">
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        {{ $donor['totalDonations'] }}
                    </p>
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        completed collections
                    </span>
                </div>
                <div class="mt-2">
                    <x-ui.badge :variant="$accountVariant" :dot="true">
                        Account: {{ $donor['accountStatus'] }}
                    </x-ui.badge>
                </div>
            </x-ui.card>
        </section>

        {{-- Profile Details + Donation History --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left Column: Personal, Contact & Clinical Information --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Personal Information
                        </h2>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">Donor ID</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white">{{ $donor['id'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Full Name</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ $donor['name'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Gender</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $donor['gender'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Date of Birth</dt>
                            <dd class="text-slate-700 dark:text-slate-200">
                                {{ $donor['dateOfBirth'] }} ({{ $donor['age'] }} yrs)
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Availability</dt>
                            <dd>
                                <x-ui.badge :variant="$availabilityVariant" :dot="true">
                                    {{ $donor['availabilityStatus'] }}
                                </x-ui.badge>
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">Account Status</dt>
                            <dd>
                                <x-ui.badge :variant="$accountVariant" :dot="true">
                                    {{ $donor['accountStatus'] }}
                                </x-ui.badge>
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Contact Information
                        </h2>
                    </x-slot:header>

                    <dl class="space-y-4 text-sm">
                        <div class="flex items-start gap-3">
                            <x-icons.icon name="envelope" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Email Address</dt>
                                <dd class="mt-0.5 font-medium break-all text-slate-900 dark:text-white">{{ $donor['email'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="phone" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Phone Number</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $donor['phone'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Residential Address</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ $donor['address'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                            <x-icons.icon name="shield-check" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Emergency Contact</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ $donor['emergencyContact'] }}</dd>
                            </div>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Clinical Review &amp; Notes
                            </h2>
                            <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                                {{ $donor['eligibilityStatus'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-3 text-sm">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold text-slate-700 dark:text-slate-200">
                                Medical Screening Assessment
                            </p>
                            <p class="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-300">
                                {{ $donor['eligibilityNote'] }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Intake Notes</p>
                            <p class="mt-1 leading-relaxed text-slate-700 dark:text-slate-200">
                                {{ $donor['medicalNotes'] ?: 'No medical notes recorded.' }}
                            </p>
                        </div>

                        <p class="border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            Final donor eligibility depends on verified medical screening criteria and backend validation rules rather than a standalone countdown.
                        </p>
                    </div>
                </x-ui.card>
            </div>

            {{-- Right Column: Donation History Table --}}
            <div class="lg:col-span-2">
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Donation History
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Recorded blood collections and screening outcomes for {{ $donor['name'] }}.
                                </p>
                            </div>

                            <x-ui.badge variant="neutral">
                                {{ count($donor['donationHistory']) }} {{ count($donor['donationHistory']) === 1 ? 'record' : 'records' }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    @if (count($donor['donationHistory']) > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Reference</th>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donation Date</th>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Collection Center</th>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Component</th>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Volume</th>
                                        <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Status</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                    @foreach ($donor['donationHistory'] as $entry)
                                        @php
                                            $historyVariant = in_array($entry['status'], ['Cleared', 'Completed'], true)
                                                ? 'success'
                                                : 'warning';
                                        @endphp
                                        <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                            <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200">
                                                {{ $entry['reference'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                                {{ $entry['date'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                                {{ $entry['center'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                                {{ $entry['component'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap font-medium text-slate-700 dark:text-slate-200">
                                                {{ $entry['volume'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <x-ui.badge :variant="$historyVariant" :dot="true">
                                                    {{ $entry['status'] }}
                                                </x-ui.badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
                            <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                <x-icons.icon name="droplet" class="size-6" />
                            </div>
                            <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                                No donation history on record
                            </h3>
                            <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">
                                Completed collections and clinical screening logs will appear here once recorded in the Donations module.
                            </p>
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>
