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

<x-app-layout :title="__('Donation Details') . ' · ' . $donation['id']" :page-title="__('Donation Details')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donations.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Donations Log') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="font-mono text-slate-700 dark:text-slate-200 dir-ltr">{{ $donation['id'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-900 dark:text-white dir-ltr">
                        {{ $donation['id'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="md" />
                    <x-ui.badge :variant="$screeningVariant" :dot="true">
                        {{ __('Screening') }}: {{ __($donation['screeningStatus']) }}
                    </x-ui.badge>
                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                        {{ __('Collection') }}: {{ __($donation['collectionStatus']) }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __($donation['donationType']) }} &middot; {{ $donation['quantityMl'] }} {{ __('ml') }} &middot; {{ $donation['donationDateFormatted'] }} {{ __('at') }} {{ $donation['collectionLocation'] }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donations.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Donations') }}</span>
                </x-ui.button>

                @if ($donation['collectionStatus'] === 'Scheduled')
                    <x-ui.button
                        variant="primary"
                        size="md"
                        type="button"
                        @click="$dispatch('open-modal', 'complete-donation-modal')"
                    >
                        <x-icons.icon name="check-circle" class="size-4" />
                        <span>{{ __('Complete Collection') }}</span>
                    </x-ui.button>

                    <button
                        type="button"
                        @click="$dispatch('open-modal', 'cancel-donation-modal')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-rose-700 shadow-xs transition hover:bg-rose-50 dark:border-slate-700 dark:bg-slate-800 dark:text-rose-400 dark:hover:bg-slate-700 cursor-pointer"
                    >
                        <x-icons.icon name="no-symbol" class="size-3.5" />
                        <span>{{ __('Cancel Collection') }}</span>
                    </button>
                @endif

                <x-ui.button variant="outline" size="md" :href="route('donations.edit', $donation['id'])">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>{{ __('Edit Donation') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success" :title="session('success')" />
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger" :title="session('error')" />
        @endif

        {{-- Top Key Metrics --}}
        <section aria-label="Donation summary metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Blood Group') }}
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="lg" />
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ __('Matched Donor Type') }}
                    </span>
                </div>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Donation Date & Volume') }}
                </p>
                <p class="mt-3 text-xl font-bold text-slate-900 dark:text-white">
                    {{ $donation['quantityMl'] }} {{ __('ml') }}
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ __($donation['donationType']) }} &middot; {{ $donation['donationDateFormatted'] }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Screening Status') }}
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$screeningVariant" :dot="true">
                        {{ __($donation['screeningStatus']) }}
                    </x-ui.badge>
                    <x-icons.icon name="shield-check" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Explicit laboratory verification status') }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    {{ __('Collection Status') }}
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <x-ui.badge :variant="$collectionVariant" :dot="true">
                        {{ __($donation['collectionStatus']) }}
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
                                {{ __('Donor Information') }}
                            </h2>
                            <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                                {{ __($donation['donorEligibilityStatus']) }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donor ID') }}</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white dir-ltr text-start">{{ $donation['donorId'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Full Name') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ __($donation['donorName']) }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Blood Group') }}</dt>
                            <dd>
                                <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="sm" />
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Phone') }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $donation['donorPhone'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Email') }}</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $donation['donorEmail'] }}</dd>
                        </div>
                    </dl>

                    <x-slot:footer>
                        <x-ui.button variant="outline" size="sm" :href="route('donors.show', $donation['donorId'])" class="w-full justify-center">
                            <x-icons.icon name="user" class="size-4" />
                            <span>{{ __('View Donor Profile') }}</span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Collection Session Metadata') }}
                        </h2>
                    </x-slot:header>

                    <dl class="space-y-4 text-sm">
                        <div class="flex items-start gap-3">
                            <x-icons.icon name="droplet" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Donation Type & Volume') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">
                                    {{ __($donation['donationType']) }} &middot; {{ $donation['quantityMl'] }} {{ __('ml') }}
                                </dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="calendar" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Donation Date') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">
                                    {{ $donation['donationDateFormatted'] }}
                                    <span class="ms-1 font-mono text-xs text-slate-400 dark:text-slate-500 dir-ltr">({{ $donation['donationDate'] }})</span>
                                </dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <x-icons.icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Collection Location') }}</dt>
                                <dd class="mt-0.5 text-slate-700 dark:text-slate-200">{{ $donation['collectionLocation'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                            <x-icons.icon name="user" class="mt-0.5 size-4 shrink-0 text-slate-400 dark:text-slate-500" />
                            <div>
                                <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ __('Staff Member') }}</dt>
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
                                    {{ __('Screening Information & Notes') }}
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Clinical laboratory verification status and serology/hematology remarks.') }}
                                </p>
                            </div>

                            <x-ui.badge :variant="$screeningVariant" :dot="true">
                                {{ __($donation['screeningStatus']) }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                {{ __('Screening Notes') }}
                            </p>
                            <p class="mt-1.5 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $donation['screeningNotes'] ?: __('No laboratory screening notes recorded.') }}
                            </p>
                        </div>

                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Laboratory screening decisions are recorded explicitly by medical staff and never assumed from collection status alone.') }}
                        </p>
                    </div>
                </x-ui.card>

                {{-- Serialized Blood Unit & Warehouse Lifecycle Card --}}
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    {{ __('Serialized Blood Unit & Inventory Lifecycle') }}
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Direct warehouse link, quarantine verification, and inventory status.') }}
                                </p>
                            </div>

                            @if ($donationModel && $donationModel->primaryBloodUnit)
                                @php
                                    $unitStatus = $donationModel->primaryBloodUnit->unit_status;
                                    $unitBadgeVariant = match ($unitStatus) {
                                        'Available' => 'success',
                                        'Reserved' => 'info',
                                        'Dispatched', 'Transfused' => 'primary',
                                        'Expired', 'Discarded' => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <x-ui.badge :variant="$unitBadgeVariant" :dot="true">
                                    {{ __($unitStatus) }}
                                </x-ui.badge>
                            @endif
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        @if ($donationModel && $donationModel->primaryBloodUnit)
                            @php $pUnit = $donationModel->primaryBloodUnit; @endphp
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Unit Code') }}</p>
                                    <p class="mt-1 font-semibold text-slate-900 dark:text-white dir-ltr">
                                        <a href="{{ route('inventory.units.show', $pUnit->id) }}" class="text-crimson-600 hover:text-crimson-700 hover:underline dark:text-crimson-400">
                                            {{ $pUnit->unit_code }}
                                        </a>
                                    </p>
                                </div>

                                <div class="rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Warehouse Status') }}</p>
                                    <p class="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {{ __($pUnit->unit_status) }}
                                    </p>
                                </div>

                                <div class="rounded-xl border border-slate-200/80 p-3 dark:border-slate-800">
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Expiry Date') }}</p>
                                    <p class="mt-1 font-semibold text-slate-900 dark:text-white">
                                        {{ $pUnit->expires_at?->format('M d, Y') ?? __('Not set') }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                                <a
                                    href="{{ route('inventory.units.show', $pUnit->id) }}"
                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400"
                                >
                                    <span>{{ __('View Unit in Inventory Directory') }}</span>
                                    <x-icons.icon name="arrow-right" class="size-3.5 rtl-flip" />
                                </a>

                                @if ($pUnit->unit_status === 'Quarantined' && $donationModel->screening_status === 'Passed')
                                    <a
                                        href="{{ route('screening.show', $donationModel->donation_code) }}"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-emerald-700 transition"
                                    >
                                        <x-icons.icon name="check-circle" class="size-3.5" />
                                        <span>{{ __('Ready for Inventory Release') }}</span>
                                    </a>
                                @elseif ($pUnit->unit_status === 'Quarantined')
                                    <a
                                        href="{{ route('screening.show', $donationModel->donation_code) }}"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                                    >
                                        <x-icons.icon name="shield-check" class="size-3.5" />
                                        <span>{{ __('Complete Screening Panel') }}</span>
                                    </a>
                                @endif
                            </div>
                        @else
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('No serialized blood unit has been generated yet. Units are created automatically upon completion of the phlebotomy collection.') }}
                            </p>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    {{ __('Collection Information & Notes') }}
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Phlebotomy session status, container handling, and clinical observations.') }}
                                </p>
                            </div>

                            <x-ui.badge :variant="$collectionVariant" :dot="true">
                                {{ __($donation['collectionStatus']) }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Component') }}</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ __($donation['donationType']) }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Measured Quantity') }}</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $donation['quantityMl'] }} {{ __('ml') }}</p>
                            </div>

                            <div class="rounded-xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Attending Staff') }}</p>
                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $donation['staffMember'] }}</p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                {{ __('Collection Notes') }}
                            </p>
                            <p class="mt-1.5 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $donation['collectionNotes'] ?: __('No collection notes recorded.') }}
                            </p>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        </div>

        @if ($donation['collectionStatus'] === 'Scheduled' && isset($donationModel))
            {{-- Complete Collection Modal --}}
            <x-ui.modal name="complete-donation-modal" :title="__('Complete Phlebotomy Collection')" max-width="md">
                <form method="POST" action="{{ route('donations.complete', $donationModel) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {!! __('Record collection completion for donation :id. This creates the primary blood unit in quarantine and initializes the mandatory laboratory screening panel.', ['id' => '<strong class="dir-ltr">' . $donation['id'] . '</strong>']) !!}
                    </p>

                    <div>
                        <label for="complete-quantity" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Volume Collected (ml)') }}
                        </label>
                        <input
                            type="number"
                            id="complete-quantity"
                            name="quantity_ml"
                            min="100"
                            max="1000"
                            value="{{ $donation['quantityMl'] }}"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                    </div>

                    <div>
                        <label for="complete-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Collection / Phlebotomy Notes') }}
                        </label>
                        <textarea
                            id="complete-notes"
                            name="collection_notes"
                            rows="3"
                            placeholder="{{ __('Vein accessibility, donor tolerance, vitals observations...') }}"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'complete-donation-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700 cursor-pointer"
                        >
                            {{ __('Confirm Completion') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            {{-- Cancel Donation Modal --}}
            <x-ui.modal name="cancel-donation-modal" :title="__('Cancel Scheduled Donation')" max-width="md">
                <form method="POST" action="{{ route('donations.cancel', $donationModel) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {!! __('Cancel scheduled donation :id.', ['id' => '<strong class="dir-ltr">' . $donation['id'] . '</strong>']) !!}
                    </p>

                    <div>
                        <label for="cancel-reason" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Reason for Cancellation') }}
                        </label>
                        <textarea
                            id="cancel-reason"
                            name="reason"
                            rows="3"
                            placeholder="{{ __('Donor did not appear, medically deferred prior to phlebotomy, or requested cancellation...') }}"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'cancel-donation-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            {{ __('Keep Scheduled') }}
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-rose-700 cursor-pointer"
                        >
                            {{ __('Confirm Cancellation') }}
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    </div>
</x-app-layout>
