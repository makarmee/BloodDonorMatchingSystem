<x-guest-layout title="Home">
    @php
        $priorityGroups = $bloodStock;
        usort($priorityGroups, static function (array $a, array $b): int {
            $ratioA = $a['minLevel'] > 0 ? $a['available'] / $a['minLevel'] : 1;
            $ratioB = $b['minLevel'] > 0 ? $b['available'] / $b['minLevel'] : 1;

            return $ratioA <=> $ratioB;
        });
    @endphp

    {{-- Hero Section --}}
    <section class="relative overflow-hidden border-b border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="pointer-events-none absolute inset-x-0 -top-24 h-72 bg-radial from-crimson-500/12 via-transparent to-transparent dark:from-crimson-500/20"></div>

        <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-14 sm:px-6 lg:grid-cols-12 lg:py-20 lg:px-8">
            <div class="space-y-6 lg:col-span-7">
                <x-ui.badge variant="crimson" :dot="true" class="py-1 text-xs">
                    {{ ($stockSummary['isSample'] ?? false) ? __('Public Portal • Synchronized Sample Supply Preview') : __('Public Portal • Live Blood Supply Coordination') }}
                </x-ui.badge>

                <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                    {{ __('Safe, fast blood coordination connecting') }}
                    <span class="text-crimson-600 dark:text-crimson-400">{{ __('donors, community drives, and hospitals.') }}</span>
                </h1>

                <p class="max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-300">
                    {{ __('Explore regional blood group availability across all 8 ABO/Rh groups, locate upcoming and active donation camps, and launch the Donor Portal to schedule your next lifesaving visit.') }}
                </p>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <x-ui.button :href="route('register')" variant="primary" size="lg">
                        <x-icons.icon name="heart-pulse" class="size-4" />
                        {{ __('Register as Donor') }}
                    </x-ui.button>

                    <x-ui.button :href="route('public.camps.index')" variant="outline" size="lg">
                        <x-icons.icon name="calendar" class="size-4" />
                        {{ __('Find Donation Camps') }}
                    </x-ui.button>

                    <x-ui.button :href="route('public.stock')" variant="ghost" size="lg">
                        {{ __('Check Blood Stock') }}
                        <x-icons.icon name="arrow-right" class="size-4" />
                    </x-ui.button>
                </div>

                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <button
                        type="button"
                        x-data
                        @click="$dispatch('open-modal', 'eligibility-check')"
                        class="inline-flex items-center gap-1.5 font-medium text-crimson-700 hover:text-crimson-800 dark:text-crimson-400 dark:hover:text-crimson-300 cursor-pointer"
                    >
                        <x-icons.icon name="shield-check" class="size-4" />
                        {{ __('Quick eligibility check') }}
                    </button>

                    <span class="text-slate-300 dark:text-slate-700" aria-hidden="true">|</span>

                    <a href="{{ route('donor-portal.dashboard') }}" class="inline-flex items-center gap-1.5 font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                        <x-icons.icon name="user" class="size-4" />
                        {{ __('Open Donor Portal Preview') }}
                    </a>
                </div>

                <div class="grid grid-cols-3 gap-4 border-t border-slate-200/80 pt-6 dark:border-slate-800">
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {{ $stockSummary['totalUnits'] }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Available Units') }} ({{ $stockSummary['totalGroups'] }} {{ __('Groups') }})
                        </p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                            {{ $campSummary['totalCamps'] }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Public Drives') }} ({{ $campSummary['totalAvailableSlots'] }} {{ __('Open Slots') }})
                        </p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-rose-600 dark:text-rose-400">
                            {{ $stockSummary['criticalCount'] }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Critical Groups') }} ({{ implode(', ', $stockSummary['urgentGroups']) ?: __('None') }})
                        </p>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-5">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <x-icons.icon name="activity" class="size-4 text-crimson-600 dark:text-crimson-400" />
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ __('Live Priority Stock Watch') }}</h2>
                            </div>
                            <x-ui.badge variant="danger" :dot="true">
                                {{ $stockSummary['criticalCount'] }} {{ __('Critical') }} &bull; {{ $stockSummary['lowCount'] }} {{ __('Low') }}
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4">
                        @foreach (array_slice($priorityGroups, 0, 4) as $item)
                            <x-bdms.stock-level-bar
                                :group="$item['group']"
                                :units="$item['available']"
                                :capacity="$item['capacity']"
                                :status="$item['status']"
                            />
                        @endforeach
                    </div>

                    <x-slot:footer>
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-slate-500 dark:text-slate-400">{{ __('Sample inventory snapshot') }}</span>
                            <a href="{{ route('public.stock') }}" class="inline-flex items-center gap-1 font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                                <span>{{ __('Inspect full stock lookup') }}</span>
                                <x-icons.icon name="arrow-right" class="size-3.5" />
                            </a>
                        </div>
                    </x-slot:footer>
                </x-ui.card>
            </div>
        </div>
    </section>

    {{-- Blood Stock Overview --}}
    <section id="blood-stock" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                    {{ __('Inventory Snapshot') }}
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Current Blood Group Availability') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Synchronized sample counts across all 8 ABO/Rh groups. Critical and low-stock blood types prioritize donor scheduling.') }}
                </p>
            </div>
            <x-ui.button :href="route('public.stock')" variant="outline" size="sm">
                {{ __('Full Blood Stock Lookup') }}
                <x-icons.icon name="arrow-right" class="size-3.5" />
            </x-ui.button>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($bloodStock as $item)
                <x-ui.card class="flex flex-col justify-between gap-3">
                    <x-bdms.stock-level-bar
                        :group="$item['group']"
                        :units="$item['available']"
                        :capacity="$item['capacity']"
                        :status="$item['status']"
                    />
                    <div class="flex items-center justify-between border-t border-slate-100 pt-2.5 text-[11px] text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <span>{{ __('Min target') }}: {{ $item['minLevel'] }} {{ __('units') }}</span>
                        <span>{{ $item['lastUpdated'] }}</span>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    </section>

    {{-- Step-by-Step Donation Process --}}
    <section id="donation-process" class="border-y border-slate-200/80 bg-white py-14 dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                        {{ __('Donor Journey') }}
                    </p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('How blood donation works from booking to screening') }}
                    </h2>
                </div>
                <x-ui.button :href="route('donor-portal.appointments.create')" variant="primary" size="sm">
                    {{ __('Schedule in Donor Portal') }}
                    <x-icons.icon name="arrow-right" class="size-3.5" />
                </x-ui.button>
            </div>

            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($donationSteps as $step)
                    <x-ui.card class="flex flex-col justify-between gap-4 bg-slate-50/70 dark:bg-slate-950/60">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex size-10 items-center justify-center rounded-xl bg-crimson-50 text-crimson-600 ring-1 ring-inset ring-crimson-200/70 dark:bg-crimson-950/70 dark:text-crimson-400 dark:ring-crimson-800/60">
                                    <x-icons.icon :name="$step['icon']" class="size-5" />
                                </div>
                                <span class="text-xs font-bold tracking-wider text-slate-400 dark:text-slate-500">
                                    {{ __('STEP') }} {{ $step['step'] }}
                                </span>
                            </div>

                            <h3 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __($step['title']) }}
                            </h3>
                            <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                {{ __($step['description']) }}
                            </p>
                        </div>

                        <div class="border-t border-slate-200/70 pt-3 text-xs font-medium text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            {{ __('Typical duration:') }} <span class="text-slate-700 dark:text-slate-200">{{ __($step['duration']) }}</span>
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Upcoming & Ongoing Donation Camps --}}
    <section id="camps" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                    {{ __('Community Drives') }}
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Upcoming & Ongoing Donation Camps') }}
                </h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Published drives open for public inspection. View full directions, walk-in rules, and remaining capacity before booking.') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button :href="route('public.camps.index')" variant="outline" size="sm">
                    {{ __('Browse All Camps') }} ({{ $campSummary['totalCamps'] }})
                    <x-icons.icon name="arrow-right" class="size-3.5" />
                </x-ui.button>
            </div>
        </div>

        <div class="mt-8 grid gap-6 md:grid-cols-3">
            @foreach ($upcomingCamps as $camp)
                @php
                    $statusVariant = $camp['status'] === 'Ongoing' ? 'success' : 'info';
                    $availabilityVariant = $camp['isBookingOpen'] ? 'crimson' : 'warning';
                @endphp

                <x-ui.card class="flex flex-col justify-between gap-5">
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <x-ui.badge :variant="$statusVariant" :dot="true">{{ $camp['status'] }}</x-ui.badge>
                            <x-ui.badge :variant="$availabilityVariant">{{ $camp['availabilityLabel'] }}</x-ui.badge>
                        </div>

                        <h3 class="mt-3 text-base font-semibold text-slate-900 dark:text-white">
                            <a href="{{ route('public.camps.show', $camp['id']) }}" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">
                                {{ $camp['name'] }}
                            </a>
                        </h3>

                        <p class="mt-1.5 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                            {{ $camp['description'] }}
                        </p>

                        <dl class="mt-4 space-y-2 text-xs text-slate-600 dark:text-slate-400">
                            <div class="flex items-center gap-2">
                                <x-icons.icon name="calendar" class="size-4 text-slate-400 shrink-0" />
                                <dd>{{ $camp['scheduleSummary'] }}</dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-icons.icon name="clock" class="size-4 text-slate-400 shrink-0" />
                                <dd>{{ $camp['startTime'] }} &ndash; {{ $camp['endTime'] }} &bull; {{ $camp['availableSlots'] }} of {{ $camp['capacity'] }} slots left</dd>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-icons.icon name="map-pin" class="size-4 text-slate-400 shrink-0" />
                                <dd>{{ $camp['venueName'] }}, {{ $camp['city'] }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3.5 dark:border-slate-800">
                        <a href="{{ route('public.camps.show', $camp['id']) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                            <span>{{ __('View Drive Details') }}</span>
                            <x-icons.icon name="arrow-right" class="size-3.5" />
                        </a>
                        @if ($camp['isBookingOpen'])
                            <a href="{{ route('donor-portal.appointments.create', ['camp' => $camp['id']]) }}" class="text-xs font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                                {{ __('Reserve Slot') }}
                            </a>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    </section>

    {{-- Role-Based Workspaces --}}
    <section id="portals" class="border-y border-slate-200/80 bg-white py-14 dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                    {{ __('Role-Based Workspaces') }}
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Dedicated portals for every part of the blood supply chain') }}
                </h2>
            </div>

            <div class="mt-8 grid gap-6 md:grid-cols-3">
                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-crimson-50 text-crimson-600 dark:bg-crimson-950/70 dark:text-crimson-400">
                            <x-icons.icon name="heart-pulse" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Donor Portal') }}</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            {{ __('Check your 56-day eligibility cooldown, manage upcoming camp appointments, and review personal donation milestones.') }}
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                        <a href="{{ route('donor-portal.dashboard') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                            {{ __('Open Donor Portal') }}
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                            <x-icons.icon name="building-hospital" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Hospital & Requester') }}</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            {{ __('Submit emergency or scheduled blood component requests, verify regional availability, and track dispatch ETA.') }}
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ auth()->check() ? route(auth()->user()->dashboardRouteName()) : route('login') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-sky-600 hover:text-sky-700 dark:text-sky-400">
                            {{ auth()->check() ? __('Open Dashboard') : __('Sign in to Hospital Portal') }}
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <x-icons.icon name="shield-check" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('Admin & Blood Bank Staff') }}</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            {{ __('Monitor unit shelf-life, log lab screening & donations, approve hospital requests, and organize donation camps.') }}
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ auth()->check() ? route(auth()->user()->dashboardRouteName()) : route('login') }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">
                            {{ auth()->check() ? __('Open Dashboard') : __('Sign in to Staff Portal') }}
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </section>

    {{-- FAQ & Demo Contact Section --}}
    <section id="faq-contact" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-12">
            {{-- FAQs --}}
            <div class="space-y-4 lg:col-span-7" x-data="{ openFaq: '{{ $faqs[0]['id'] ?? '' }}' }">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                        {{ __('Donor Guidance') }}
                    </p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('Frequently Asked Questions') }}
                    </h2>
                </div>

                <div class="space-y-3">
                    @foreach ($faqs as $faq)
                        <x-ui.card :padding="false">
                            <button
                                type="button"
                                @click="openFaq = openFaq === '{{ $faq['id'] }}' ? '' : '{{ $faq['id'] }}'"
                                :aria-expanded="openFaq === '{{ $faq['id'] }}'"
                                class="flex w-full items-center justify-between gap-4 px-5 py-4 text-start text-sm font-semibold text-slate-900 hover:bg-slate-50/70 dark:text-white dark:hover:bg-slate-800/40 rounded-xl cursor-pointer transition"
                            >
                                <span>{{ __($faq['question']) }}</span>
                                <x-icons.icon
                                    name="chevron-down"
                                    class="size-4 shrink-0 text-slate-400 transition-transform duration-200"
                                    x-bind:class="openFaq === '{{ $faq['id'] }}' ? 'rotate-180 text-crimson-600 dark:text-crimson-400' : ''"
                                />
                            </button>

                            <div
                                x-show="openFaq === '{{ $faq['id'] }}'"
                                x-cloak
                                class="border-t border-slate-100 px-5 py-3.5 text-sm leading-relaxed text-slate-600 dark:border-slate-800 dark:text-slate-300"
                            >
                                {{ __($faq['answer']) }}
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            </div>

            {{-- Contact & Preview Disclaimer --}}
            <div class="lg:col-span-5 space-y-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                        {{ __('Public Inquiry Channels') }}
                    </p>
                    <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('Contact & Coordination Desk') }}
                    </h2>
                </div>

                <x-ui.alert variant="warning" :title="__('Demo Placeholder Notice')" :dismissible="false">
                    {{ __($contactInfo['notice']) }}
                </x-ui.alert>

                <x-ui.card class="space-y-4">
                    <dl class="space-y-3.5 text-sm">
                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <x-icons.icon name="phone" class="size-4" />
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Support Phone') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white dir-ltr">{{ $contactInfo['phone'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <x-icons.icon name="envelope" class="size-4" />
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Support Email') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white dir-ltr">{{ $contactInfo['email'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <x-icons.icon name="map-pin" class="size-4" />
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Regional Hub') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $contactInfo['address'] }}</dd>
                            </div>
                        </div>

                        <div class="flex items-start gap-3">
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <x-icons.icon name="clock" class="size-4" />
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Service Window') }}</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $contactInfo['hours'] }}</dd>
                            </div>
                        </div>
                    </dl>

                    <div class="border-t border-slate-100 pt-4 flex flex-wrap gap-2 dark:border-slate-800">
                        <x-ui.button :href="route('donor-portal.eligibility')" variant="outline" size="sm">
                            {{ __('Check Eligibility Details') }}
                        </x-ui.button>
                        <x-ui.button :href="route('public.camps.index')" variant="primary" size="sm">
                            {{ __('Explore Donation Camps') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </section>

    {{-- Quick Eligibility Modal --}}
    <x-ui.modal name="eligibility-check" :title="__('Quick Donor Eligibility Checklist')" maxWidth="md">
        <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>{{ __('Age between 18 and 65 years old with valid government or donor ID.') }}</span>
            </li>
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>{{ __('Minimum body weight of 50 kg (110 lbs) and normal hemoglobin level.') }}</span>
            </li>
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>{{ __('At least 56 days (8 weeks) since your last whole blood donation.') }}</span>
            </li>
        </ul>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <x-ui.button variant="ghost" @click="$dispatch('close-modal', 'eligibility-check')">{{ __('Close') }}</x-ui.button>
            <x-ui.button :href="route('donor-portal.eligibility')" variant="outline">{{ __('Full Eligibility Guide') }}</x-ui.button>
            <x-ui.button :href="route('register')" variant="primary">{{ __('Continue to Registration') }}</x-ui.button>
        </div>
    </x-ui.modal>
</x-guest-layout>
