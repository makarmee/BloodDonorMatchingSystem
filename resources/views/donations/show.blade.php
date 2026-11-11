@php
    $screeningVariant = match ($donation['screeningStatus']) {
        'Passed' => 'success',
        'Failed' => 'danger',
        default => 'warning',
    };

    $collectionVariant = match ($donation['collectionStatus']) {
        'Completed' => 'success',
        'Scheduled' => 'info',
        default => 'danger',
    };

    $eligibilityVariant = match ($donation['donorEligibilityStatus']) {
        'Eligible' => 'success',
        'Not Eligible' => 'danger',
        default => 'warning',
    };
@endphp

<x-app-layout :title="'Donation Details · ' . $donation['id']" page-title="Donation Details" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donations.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Donations Log
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="font-mono text-slate-700 dark:text-slate-200">{{ $donation['id'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $donation['id'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="md" />
                    <x-ui.badge :variant="$screeningVariant" :dot="true">
                        Screening: {{ $donation['screeningStatus'] }}
                    </x-ui.badge>
                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                        Collection: {{ $donation['collectionStatus'] }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ $donation['donationType'] }} &middot; {{ $donation['quantityMl'] }} ml &middot; {{ $donation['donationDateFormatted'] }} at {{ $donation['collectionLocation'] }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donations.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Donations</span>
                </x-ui.button>

                <x-ui.button variant="primary" size="md" :href="route('donations.edit', $donation['id'])">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>Edit Donation</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Top Key Metrics --}}
        <section aria-label="Donation summary metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Blood Group
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="lg" />
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        Matched Donor Type
                    </span>
                </div>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Donation Date &amp; Volume
                </p>
                <p class="mt-3 text-xl font-bold text-slate-900 dark:text-white">
                    {{ $donation['quantityMl'] }} ml
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ $donation['donationType'] }} &middot; {{ $donation['donationDateFormatted'] }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Screening Status
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$screeningVariant" :dot="true">
                        {{ $donation['screeningStatus'] }}
                    </x-ui.badge>
                    <x-icons.icon name="shield-check" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Explicit laboratory verification status
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Collection Status
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                        {{ $donation['collectionStatus'] }}
                    </x-ui.badge>
                    <x-icons.icon name="heart-pulse" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    {{ $donation['collectionLocation'] }}
                </p>
            </x-ui.card>
        </section>

        {{-- Main Detail Grid --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left Column: Donor Information & Metadata --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Donor Information
                            </h2>
                            <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                                {{ $donation['donorEligibilityStatus'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">Donor ID</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white">{{ $donation['donorId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Full Name</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $donation['donorName'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Blood Group</dt>
                            <dd>
                                <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="sm" />
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Phone</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $donation['donorPhone'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200">{{ $donation['donorEmail'] }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donors.show', $donation['donorId'])" class="w-full justify-center">
                            <x-icons.icon name="user" class="size-4" />
                            <span>View Donor Profile</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Collection Session Metadata
                        </h2>
                    </x-slot:header>

                    <dl class="space-y-4 text-sm">
                        <div class="flex items-start gap-3">
                            <x-icons.icon name="droplet" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Donation Type &amp; Volume</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">
                                    {{ $donation['donationType'] }} &middot; {{ $donation['quantityMl'] }} ml
                                </dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="calendar" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Donation Date</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">
                                    {{ $donation['donationDateFormatted'] }}
                                    <span class="ml-1 font-mono text-xs text-slate-400 dark:text-slate-500">({{ $donation['donationDate'] }})</span>
                                </dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Collection Location</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ $donation['collectionLocation'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                            <x-icons.icon name="user" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Staff Member</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $donation['staffMember'] }}</dd>
                            </div>
                        </div>
                    </dl>
                </x-ui.card>
            </div>

            {{-- Right Column: Screening & Collection Details --}}
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Screening Information &amp; Notes
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Clinical laboratory verification status and serology/hematology remarks.
                                </p>
                            </div>

                            <x-ui.badge :variant="$screeningVariant" :dot="true">
                                {{ $donation['screeningStatus'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                Screening Notes
                            </p>
                            <p class="mt-1.5 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $donation['screeningNotes'] ?: 'No laboratory screening notes recorded.' }}
                            </p>
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Laboratory screening decisions are recorded explicitly by medical staff and never assumed from collection status alone.
                        </p>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Collection Information &amp; Notes
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Phlebotomy session status, container handling, and clinical observations.
                                </p>
                            </div>

                            <x-ui.badge :variant="$collectionVariant" :dot="true">
                                {{ $donation['collectionStatus'] }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Component</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $donation['donationType'] }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Measured Quantity</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $donation['quantityMl'] }} ml</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Attending Staff</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $donation['staffMember'] }}</p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                Collection Notes
                            </p>
                            <p class="mt-1.5 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $donation['collectionNotes'] ?: 'No collection notes recorded.' }}
                            </p>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>

