<x-guest-layout :title="$camp['name']">
    @php
        $statusVariant = $camp['status'] === 'Ongoing' ? 'success' : 'info';
        $availabilityVariant = $camp['isBookingOpen'] ? 'crimson' : 'warning';
        $fillPercent = $camp['capacity'] > 0
            ? min(100, (int) round(($camp['registeredDonors'] / $camp['capacity']) * 100))
            : 100;
    @endphp

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        {{-- Breadcrumb --}}
        <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <a href="{{ route('home') }}" class="hover:text-crimson-600 dark:hover:text-crimson-400">Home</a>
            <x-icons.icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('public.camps.index') }}" class="hover:text-crimson-600 dark:hover:text-crimson-400">Donation Camps</a>
            <x-icons.icon name="chevron-right" class="size-3.5" />
            <span class="font-medium text-slate-900 dark:text-white">{{ $camp['id'] }}</span>
        </nav>

        {{-- Hero Camp Summary Card --}}
        <x-ui.card>
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-3 lg:max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge variant="neutral">{{ $camp['id'] }}</x-ui.badge>
                        <x-ui.badge :variant="$statusVariant" :dot="true">{{ $camp['status'] }}</x-ui.badge>
                        <x-ui.badge :variant="$availabilityVariant">{{ $camp['availabilityLabel'] }}</x-ui.badge>
                        <x-ui.badge variant="neutral">
                            {{ $camp['allowWalkIn'] ? 'Walk-ins Permitted' : 'Appointments Only' }}
                        </x-ui.badge>
                    </div>

                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                        {{ $camp['name'] }}
                    </h1>

                    <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                        {{ $camp['description'] }}
                    </p>
                </div>

                <div class="flex shrink-0 flex-col gap-2.5 sm:flex-row lg:flex-col lg:min-w-60">
                    @if ($camp['isBookingOpen'])
                        <x-ui.button :href="route('donor-portal.appointments.create', ['camp' => $camp['id']])" variant="primary" size="md">
                            <x-icons.icon name="heart-pulse" class="size-4" />
                            Book Appointment in Donor Portal
                        </x-ui.button>
                    @else
                        <x-ui.button :href="route('public.camps.index')" variant="outline" size="md">
                            <x-icons.icon name="arrow-left" class="size-4" />
                            Explore Open Drives
                        </x-ui.button>
                    @endif

                    <x-ui.button :href="route('donor-portal.eligibility')" variant="outline" size="sm">
                        <x-icons.icon name="shield-check" class="size-4" />
                        Verify Eligibility First
                    </x-ui.button>
                </div>
            </div>

            <div class="mt-6">
                @if ($camp['isBookingOpen'])
                    <x-ui.alert variant="info" title="Preview Registration Flow" :dismissible="false">
                        Selecting &ldquo;Book Appointment in Donor Portal&rdquo; opens the Donor Portal appointment preview with {{ $camp['id'] }} pre-selected ({{ $camp['availableSlots'] }} slot(s) currently open).
                    </x-ui.alert>
                @else
                    <x-ui.alert variant="warning" :title="$camp['availabilityLabel']" :dismissible="false">
                        {{ $camp['availabilityNote'] }} You can return to the public directory to select another active camp.
                    </x-ui.alert>
                @endif
            </div>
        </x-ui.card>

        {{-- Detailed Schedule, Venue, Capacity, and Guidance --}}
        <div class="grid gap-6 lg:grid-cols-12">
            <div class="space-y-6 lg:col-span-7">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <x-icons.icon name="map-pin" class="size-4 text-crimson-600 dark:text-crimson-400" />
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Venue &amp; Arrival Details
                            </h2>
                        </div>
                    </x-slot:header>

                    <dl class="space-y-4 text-sm">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Venue Name</dt>
                            <dd class="mt-1 font-medium text-slate-900 dark:text-white">{{ $camp['venueName'] }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Street Address &amp; District</dt>
                            <dd class="mt-1 text-slate-700 dark:text-slate-300">{{ $camp['fullAddress'] }}, {{ $camp['city'] }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Arrival &amp; Parking Guidance</dt>
                            <dd class="mt-1 text-slate-600 dark:text-slate-400">{{ $camp['locationNotes'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <x-icons.icon name="clipboard-list" class="size-4 text-crimson-600 dark:text-crimson-400" />
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Donor Preparation &amp; Requirements
                            </h2>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4 text-sm text-slate-600 dark:text-slate-300">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Intake Requirements</p>
                            <p class="mt-1 leading-relaxed">{{ $camp['donorRequirements'] }}</p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Drive Notice</p>
                            <p class="mt-1 leading-relaxed">{{ $camp['additionalNotes'] }}</p>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6 lg:col-span-5">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <x-icons.icon name="calendar" class="size-4 text-crimson-600 dark:text-crimson-400" />
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Schedule &amp; Capacity
                            </h2>
                        </div>
                    </x-slot:header>

                    <dl class="space-y-3.5 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500 dark:text-slate-400">Drive Date(s)</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $camp['scheduleSummary'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500 dark:text-slate-400">Collection Hours</dt>
                            <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ $camp['startTime'] }} &ndash; {{ $camp['endTime'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500 dark:text-slate-400">Registration Deadline</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $camp['registrationDeadlineFormatted'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-slate-500 dark:text-slate-400">Walk-in Donations</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">
                                {{ $camp['allowWalkIn'] ? 'Accepted while slots remain' : 'Not permitted' }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-5 space-y-2 rounded-xl bg-slate-50 p-3.5 dark:bg-slate-950/60">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-slate-600 dark:text-slate-300">Available Donor Capacity</span>
                            <span class="font-bold tabular-nums text-slate-900 dark:text-white">
                                {{ $camp['availableSlots'] }} of {{ $camp['capacity'] }} remaining
                            </span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                            <div
                                class="h-full rounded-full {{ $camp['availableSlots'] === 0 ? 'bg-rose-500' : 'bg-emerald-500' }}"
                                style="width: {{ $fillPercent }}%"
                            ></div>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card class="space-y-3">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Public Coordination Channel [Demo Placeholder]
                    </h2>
                    <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                        Internal staff identities and donor participant logs are never exposed on public drive pages. For preview inquiries:
                    </p>
                    <p class="text-xs font-medium text-slate-800 dark:text-slate-200">
                        [Demo Placeholder] support@demo-bdms.invalid &bull; +0 (000) 000-0100
                    </p>
                </x-ui.card>
            </div>
        </div>

        {{-- Peer Open Camps --}}
        @if (count($otherCamps) > 0)
            <section aria-labelledby="other-camps-heading" class="space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 id="other-camps-heading" class="text-lg font-bold tracking-tight text-slate-900 dark:text-white">
                        Other Active &amp; Upcoming Camps
                    </h2>
                    <a href="{{ route('public.camps.index') }}" class="text-xs font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                        View all public camps &rarr;
                    </a>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($otherCamps as $peer)
                        <x-ui.card class="flex flex-col justify-between gap-3">
                            <div>
                                <div class="flex items-center justify-between gap-2">
                                    <x-ui.badge :variant="$peer['status'] === 'Ongoing' ? 'success' : 'info'" :dot="true">{{ $peer['status'] }}</x-ui.badge>
                                    <span class="text-xs font-medium tabular-nums text-slate-500 dark:text-slate-400">
                                        {{ $peer['availableSlots'] }} slots left
                                    </span>
                                </div>
                                <h3 class="mt-2.5 text-base font-semibold text-slate-900 dark:text-white">
                                    <a href="{{ route('public.camps.show', $peer['id']) }}" class="hover:text-crimson-600 dark:hover:text-crimson-400">
                                        {{ $peer['name'] }}
                                    </a>
                                </h3>
                                <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                                    {{ $peer['scheduleSummary'] }} &bull; {{ $peer['venueName'] }}, {{ $peer['city'] }}
                                </p>
                            </div>
                            <div>
                                <a href="{{ route('public.camps.show', $peer['id']) }}" class="text-xs font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                                    Inspect Drive &rarr;
                                </a>
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-guest-layout>

