<x-guest-layout title="Home">
    {{-- Hero Section --}}
    <section class="relative overflow-hidden border-b border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="pointer-events-none absolute inset-x-0 -top-24 h-72 bg-radial from-crimson-500/12 via-transparent to-transparent dark:from-crimson-500/20"></div>

        <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-14 sm:px-6 lg:grid-cols-12 lg:py-20 lg:px-8">
            <div class="space-y-6 lg:col-span-7">
                <x-ui.badge variant="crimson" :dot="true" class="py-1 text-xs">
                    24/7 Regional Blood Supply &amp; Donor Coordination
                </x-ui.badge>

                <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-5xl dark:text-white">
                    Safe, fast blood coordination connecting
                    <span class="text-crimson-600 dark:text-crimson-400">donors, blood banks, and hospitals.</span>
                </h1>

                <p class="max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg dark:text-slate-300">
                    Manage blood unit inventory across all 8 ABO/Rh groups, book donor appointments, and prioritize critical hospital requests from one unified clinical platform.
                </p>

                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <x-ui.button :href="route('register')" variant="primary" size="lg">
                        <x-icons.icon name="heart-pulse" class="size-4" />
                        Register as Donor
                    </x-ui.button>

                    <x-ui.button :href="route('login')" variant="outline" size="lg">
                        Sign in to Portal
                        <x-icons.icon name="arrow-right" class="size-4" />
                    </x-ui.button>

                    <button
                        type="button"
                        x-data
                        @click="$dispatch('open-modal', 'eligibility-check')"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-crimson-700 hover:text-crimson-800 dark:text-crimson-400 dark:hover:text-crimson-300 cursor-pointer"
                    >
                        <x-icons.icon name="shield-check" class="size-4" />
                        Quick eligibility check
                    </button>
                </div>

                <div class="grid grid-cols-3 gap-4 border-t border-slate-200/80 pt-6 dark:border-slate-800">
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">341</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Screened Units Ready</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">1,284</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Verified Donors</p>
                    </div>
                    <div>
                        <p class="text-2xl font-bold tabular-nums text-slate-900 dark:text-white">34 min</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Avg Emergency Dispatch</p>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-5">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <x-icons.icon name="activity" class="size-4 text-crimson-600 dark:text-crimson-400" />
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Live Priority Stock Watch</h2>
                            </div>
                            <x-ui.badge variant="danger" :dot="true">Urgent Need</x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="space-y-4">
                        @foreach (array_slice($bloodStock, 0, 4) as $item)
                            <x-bdms.stock-level-bar
                                :group="$item['group']"
                                :units="$item['units']"
                                :capacity="$item['capacity']"
                            />
                        @endforeach
                    </div>

                    <x-slot:footer>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-500 dark:text-slate-400">Updated from central inventory</span>
                            <a href="#blood-stock" class="font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                                View all 8 groups &rarr;
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
                    Inventory Snapshot
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Current Blood Group Availability
                </h2>
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400">
                Groups below 25% capacity are flagged for priority donor outreach.
            </p>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($bloodStock as $item)
                <x-ui.card>
                    <x-bdms.stock-level-bar
                        :group="$item['group']"
                        :units="$item['units']"
                        :capacity="$item['capacity']"
                    />
                </x-ui.card>
            @endforeach
        </div>
    </section>

    {{-- Three-Role Portal Overview --}}
    <section id="portals" class="border-y border-slate-200/80 bg-white py-14 dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                    Role-Based Workspaces
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Dedicated portals for every part of the blood supply chain
                </h2>
            </div>

            <div class="mt-8 grid gap-6 md:grid-cols-3">
                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-crimson-50 text-crimson-600 dark:bg-crimson-950/70 dark:text-crimson-400">
                            <x-icons.icon name="heart-pulse" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Donor Portal</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            Check donation cooldown eligibility, book appointments at nearby blood drives, and access digital donor records.
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ route('dashboard', ['role' => 'donor']) }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                            Preview Donor Portal
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400">
                            <x-icons.icon name="building-hospital" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Hospital &amp; Requester</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            Submit emergency or scheduled blood component requests, verify regional availability, and track dispatch ETA.
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ route('dashboard', ['role' => 'hospital']) }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-sky-600 hover:text-sky-700 dark:text-sky-400">
                            Preview Hospital Portal
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col justify-between">
                    <div class="space-y-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                            <x-icons.icon name="shield-check" class="size-5" />
                        </div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Admin &amp; Blood Bank Staff</h3>
                        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                            Monitor unit shelf-life, log lab screening &amp; donations, approve hospital requests, and organize donation camps.
                        </p>
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ route('dashboard', ['role' => 'staff']) }}" class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400">
                            Preview Staff Dashboard
                            <x-icons.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </x-ui.card>
            </div>
        </div>
    </section>

    {{-- Upcoming Donation Camps --}}
    <section id="camps" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-crimson-600 dark:text-crimson-400">
                    Community Drives
                </p>
                <h2 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Upcoming Blood Donation Camps
                </h2>
            </div>
            <x-ui.button :href="route('register')" variant="outline" size="sm">
                Reserve a Donor Slot
            </x-ui.button>
        </div>

        <div class="mt-8 grid gap-6 md:grid-cols-3">
            @foreach ($upcomingCamps as $camp)
                <x-ui.card>
                    <div class="flex items-start justify-between gap-3">
                        <x-ui.badge variant="crimson">{{ $camp['date'] }}</x-ui.badge>
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            {{ $camp['slotsLeft'] }} slots left
                        </span>
                    </div>

                    <h3 class="mt-3 text-base font-semibold text-slate-900 dark:text-white">
                        {{ $camp['title'] }}
                    </h3>

                    <dl class="mt-4 space-y-2 text-xs text-slate-600 dark:text-slate-400">
                        <div class="flex items-center gap-2">
                            <x-icons.icon name="clock" class="size-4 text-slate-400" />
                            <dd>{{ $camp['time'] }}</dd>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-icons.icon name="map-pin" class="size-4 text-slate-400" />
                            <dd>{{ $camp['location'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            @endforeach
        </div>
    </section>

    {{-- Quick Eligibility Modal --}}
    <x-ui.modal name="eligibility-check" title="Quick Donor Eligibility Checklist" maxWidth="md">
        <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>Age between 18 and 65 years old with valid government or donor ID.</span>
            </li>
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>Minimum body weight of 50 kg (110 lbs) and normal hemoglobin level.</span>
            </li>
            <li class="flex items-start gap-2.5">
                <x-icons.icon name="check-circle" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>At least 56 days (8 weeks) since your last whole blood donation.</span>
            </li>
        </ul>

        <div class="mt-6 flex justify-end gap-2">
            <x-ui.button variant="ghost" @click="$dispatch('close-modal', 'eligibility-check')">Close</x-ui.button>
            <x-ui.button :href="route('register')" variant="primary">Continue to Registration</x-ui.button>
        </div>
    </x-ui.modal>
</x-guest-layout>

