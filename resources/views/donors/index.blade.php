<x-app-layout title="Donors Management" page-title="Donors" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Donors Directory
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Manage registered blood donors, verified clinical eligibility statuses, and contact records.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('donors.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>Add Donor</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="donorsDirectory({ donors: @js($donors) })">
        {{-- Overview Statistics Cards --}}
        <section aria-label="Donor overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                label="Total Donors"
                :value="$summary['totalDonors']"
                trend="Registered community & hospital donors"
                trend-tone="neutral"
                icon="users"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Active Donors"
                :value="$summary['activeDonors']"
                trend="Active donor accounts in registry"
                trend-tone="up"
                icon="heart-pulse"
                accent="emerald"
            />

            <x-bdms.stat-card
                label="Eligible Donors"
                :value="$summary['eligibleDonors']"
                trend="Cleared under clinical screening criteria"
                trend-tone="up"
                icon="shield-check"
                accent="sky"
            />

            <x-bdms.stat-card
                label="New Donors This Month"
                :value="$summary['newThisMonth']"
                trend="Registered within the current cycle"
                trend-tone="neutral"
                icon="calendar"
                accent="amber"
            />
        </section>

        {{-- Eligibility Policy Notice --}}
        <x-ui.alert variant="info" title="Verified Medical Eligibility Criteria">
            Eligibility badges (<strong>Eligible</strong>, <strong>Not Eligible</strong>, and <strong>Pending Review</strong>) reflect verified clinical screening assessments and backend rules rather than a fixed frontend date countdown.
        </x-ui.alert>

        {{-- Donors Management Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Registered Donors
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($donors) }}</span> of {{ count($donors) }} donor records
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                x-show="hasActiveFilters"
                                x-cloak
                                @click="resetFilters()"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            >
                                <x-icons.icon name="x-mark" class="size-3.5" />
                                <span>Reset filters</span>
                            </button>

                            <label for="donors-per-page" class="sr-only">Rows per page</label>
                            <select
                                id="donors-per-page"
                                x-model.number="perPage"
                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            >
                                <option value="5">5 / page</option>
                                <option value="10">10 / page</option>
                            </select>
                        </div>
                    </div>

                    {{-- Search & Filter Controls --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="donor-search" class="sr-only">Search by donor name, ID, or phone</label>
                            <x-ui.input
                                id="donor-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search name, ID, or phone..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="filter-blood-group" class="sr-only">Filter by blood group</label>
                            <select
                                id="filter-blood-group"
                                x-model="bloodGroupFilter"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">All Blood Groups</option>
                                @foreach ($bloodGroups as $group)
                                    <option value="{{ $group }}">{{ $group }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="filter-eligibility" class="sr-only">Filter by eligibility status</label>
                            <select
                                id="filter-eligibility"
                                x-model="eligibilityFilter"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">All Eligibility Statuses</option>
                                <option value="Eligible">Eligible</option>
                                <option value="Not Eligible">Not Eligible</option>
                                <option value="Pending Review">Pending Review</option>
                            </select>
                        </div>

                        <div>
                            <label for="filter-account" class="sr-only">Filter by account status</label>
                            <select
                                id="filter-account"
                                x-model="accountFilter"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">All Account Statuses</option>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Donor ID</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Full Name</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Blood Group</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Gender</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Phone Number</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Last Donation Date</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Eligibility Status</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">Account Status</th>
                            <th scope="col" class="px-6 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @foreach ($donors as $index => $donor)
                            @php
                                $eligibilityVariant = match ($donor['eligibilityStatus']) {
                                    'Eligible' => 'success',
                                    'Not Eligible' => 'danger',
                                    default => 'warning',
                                };

                                $accountVariant = $donor['accountStatus'] === 'Active' ? 'info' : 'neutral';

                                $initials = collect(explode(' ', $donor['name']))
                                    ->filter()
                                    ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
                                    ->take(2)
                                    ->implode('');
                            @endphp

                            <tr
                                x-show="isRowVisible({{ $index }})"
                                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                            >
                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-700 dark:text-slate-200">
                                    <a
                                        href="{{ route('donors.show', $donor['id']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $donor['id'] }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-crimson-50 text-xs font-bold text-crimson-700 ring-1 ring-crimson-200/70 dark:bg-crimson-500/15 dark:text-crimson-300 dark:ring-crimson-500/30">
                                            {{ $initials }}
                                        </span>
                                        <div>
                                            <a
                                                href="{{ route('donors.show', $donor['id']) }}"
                                                class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                            >
                                                {{ $donor['name'] }}
                                            </a>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                                {{ $donor['email'] }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-bdms.blood-group-badge :group="$donor['bloodGroup']" size="sm" />
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ $donor['gender'] }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icons.icon name="phone" class="size-3.5 text-slate-400 dark:text-slate-500" />
                                        <span>{{ $donor['phone'] }}</span>
                                    </span>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    @if ($donor['lastDonationDate'])
                                        <span>{{ $donor['lastDonationDate'] }}</span>
                                    @else
                                        <span class="text-xs text-slate-400 italic dark:text-slate-500">No donations yet</span>
                                    @endif
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                                        {{ $donor['eligibilityStatus'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$accountVariant" :dot="true">
                                        {{ $donor['accountStatus'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <x-ui.button variant="ghost" size="sm" :href="route('donors.show', $donor['id'])">
                                            <x-icons.icon name="eye" class="size-4" />
                                            <span>View</span>
                                        </x-ui.button>

                                        <x-ui.button variant="outline" size="sm" :href="route('donors.edit', $donor['id'])">
                                            <x-icons.icon name="pencil" class="size-3.5" />
                                            <span>Edit</span>
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Empty State --}}
            <div
                x-show="totalFiltered === 0"
                x-cloak
                class="flex flex-col items-center justify-center px-6 py-14 text-center"
            >
                <div class="flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-icons.icon name="users" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No matching donors found
                </h3>
                <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">
                    Try adjusting your search query or clearing the blood group, eligibility, or account status filters.
                </p>
                <button
                    type="button"
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-crimson-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700"
                >
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Clear all filters</span>
                </button>
            </div>

            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingFrom"></span>
                        to <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingTo"></span>
                        of <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered"></span> donors
                    </p>

                    <nav aria-label="Donors pagination" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            @click="prevPage()"
                            :disabled="safePage <= 1"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            <x-icons.icon name="chevron-left" class="size-3.5" />
                            <span>Prev</span>
                        </button>

                        <template x-for="page in pageNumbers" :key="page">
                            <button
                                type="button"
                                @click="setPage(page)"
                                x-text="page"
                                :class="page === safePage
                                    ? 'bg-crimson-600 text-white border-crimson-600 shadow-xs'
                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
                                class="inline-flex size-8 items-center justify-center rounded-lg border text-xs font-semibold transition"
                            ></button>
                        </template>

                        <button
                            type="button"
                            @click="nextPage()"
                            :disabled="safePage >= totalPages"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                        >
                            <span>Next</span>
                            <x-icons.icon name="chevron-right" class="size-3.5" />
                        </button>
                    </nav>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-app-layout>

