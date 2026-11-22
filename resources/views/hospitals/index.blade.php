<x-app-layout :title="__('Partner Hospitals')" :page-title="__('Partner Hospitals')" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Partner Hospitals Queue') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Review incoming registrations, verify medical facilities, and manage partner access.') }}
                </p>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Overview Statistics Cards --}}
        <section aria-label="Hospital verification overview" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                :label="__('Total Facilities')"
                :value="$summary['total']"
                :trend="__('All registered partner facilities')"
                trend-tone="neutral"
                icon="building-hospital"
                accent="sky"
            />

            <x-bdms.stat-card
                :label="__('Pending Review')"
                :value="$summary['pending']"
                :trend="__('Awaiting administrative verification')"
                trend-tone="neutral"
                icon="clock"
                accent="amber"
            />

            <x-bdms.stat-card
                :label="__('Verified Partners')"
                :value="$summary['verified']"
                :trend="__('Authorized to request blood units')"
                trend-tone="up"
                icon="shield-check"
                accent="emerald"
            />

            <x-bdms.stat-card
                :label="__('Suspended Facilities')"
                :value="$summary['suspended']"
                :trend="__('Request access currently revoked')"
                trend-tone="down"
                icon="exclamation-triangle"
                accent="crimson"
            />
        </section>

        {{-- Verification Policy Notice --}}
        <x-ui.alert variant="info" :title="__('Hospital Verification Workflow')">
            {{ __('Partner hospitals must be verified by an administrator before their clinical coordinators can place blood requests. Pending facilities appear first in the review queue.') }}
        </x-ui.alert>

        {{-- Hospitals Registry Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <form method="GET" action="{{ route('hospitals.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative w-full max-w-sm">
                        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-slate-400">
                            <x-icons.icon name="magnifying-glass" class="size-4" />
                        </div>
                        <input
                            type="search"
                            name="search"
                            value="{{ $search }}"
                            placeholder="{{ __('Search facility name, code, city...') }}"
                            class="w-full rounded-xl border border-slate-300 bg-white py-2 ps-9 pe-3.5 text-sm text-slate-900 shadow-xs transition placeholder:text-slate-400 focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <label for="status-filter" class="sr-only">{{ __('Filter by Status') }}</label>
                        <select
                            id="status-filter"
                            name="status"
                            onchange="this.form.submit()"
                            class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                        >
                            <option value="">{{ __('All Verification Statuses') }}</option>
                            <option value="Pending" @selected($currentStatus === 'Pending')>{{ __('Pending Review') }}</option>
                            <option value="Verified" @selected($currentStatus === 'Verified')>{{ __('Verified') }}</option>
                            <option value="Suspended" @selected($currentStatus === 'Suspended')>{{ __('Suspended') }}</option>
                        </select>

                        <x-ui.button variant="secondary" size="sm" type="submit">
                            {{ __('Filter') }}
                        </x-ui.button>

                        @if ($search || $currentStatus)
                            <x-ui.button variant="ghost" size="sm" :href="route('hospitals.index')">
                                {{ __('Reset') }}
                            </x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-start text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-start text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Facility Code') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Hospital Name') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('City') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Primary Contact') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Registered') }}</th>
                            <th scope="col" class="px-6 py-3.5 text-end whitespace-nowrap">{{ __('Actions') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @forelse ($hospitals as $hospital)
                            @php
                                $badgeVariant = match ($hospital->verification_status) {
                                    'Verified' => 'success',
                                    'Suspended' => 'danger',
                                    default => 'warning',
                                };
                            @endphp
                            <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-700 dark:text-slate-200 dir-ltr text-start">
                                    <a
                                        href="{{ route('hospitals.show', $hospital) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $hospital->hospital_code }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                                            <x-icons.icon name="building-hospital" class="size-4.5" />
                                        </span>
                                        <div>
                                            <a
                                                href="{{ route('hospitals.show', $hospital) }}"
                                                class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                            >
                                                {{ $hospital->name }}
                                            </a>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                                {{ $hospital->address }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ $hospital->city }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-slate-900 font-medium dark:text-slate-100">
                                        {{ $hospital->primary_contact_name }}
                                    </div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">
                                        <span class="dir-ltr text-start">{{ $hospital->primary_phone }}</span> &bull; <span class="dir-ltr text-start">{{ $hospital->primary_email }}</span>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$badgeVariant" :dot="true">
                                        {{ __($hospital->verification_status) }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">
                                    {{ $hospital->created_at?->format('M d, Y') ?? '—' }}
                                </td>

                                <td class="px-6 py-4 text-end whitespace-nowrap">
                                    <x-ui.button variant="outline" size="sm" :href="route('hospitals.show', $hospital)">
                                        <x-icons.icon name="eye" class="size-3.5" />
                                        <span>{{ __('View') }}</span>
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                                        <x-icons.icon name="building-hospital" class="size-6 text-slate-400" />
                                    </div>
                                    <p class="mt-3 font-semibold text-slate-900 dark:text-white">{{ __('No partner hospitals found') }}</p>
                                    <p class="mt-1 text-xs">{{ __('Try adjusting your search criteria or status filter.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($hospitals->hasPages())
                <x-slot:footer>
                    {{ $hospitals->links() }}
                </x-slot:footer>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>

