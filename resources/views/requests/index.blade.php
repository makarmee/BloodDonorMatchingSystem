<x-app-layout title="Blood Requests Management" page-title="Blood Requests" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Blood Requests Management
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Triage hospital blood orders, monitor urgency levels, and track partial or full dispatch fulfillment.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('requests.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>New Blood Request</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="requestsDirectory({ requests: @js($requests) })">
        {{-- Overview Statistics Cards (6 required cards) --}}
        <section aria-label="Blood requests overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-bdms.stat-card
                label="Total Requests"
                :value="$summary['totalRequests']"
                trend="All active and closed hospital orders"
                trend-tone="neutral"
                icon="clipboard-list"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Pending Requests"
                :value="$summary['pendingRequests']"
                trend="Awaiting triage review & approval"
                trend-tone="neutral"
                icon="clock"
                accent="amber"
            />

            <x-bdms.stat-card
                label="Urgent Requests"
                :value="$summary['urgentRequests']"
                trend="High & Critical priority orders"
                trend-tone="down"
                icon="exclamation-triangle"
                accent="crimson"
            />

            <x-bdms.stat-card
                label="Fulfilled Requests"
                :value="$summary['fulfilledRequests']"
                trend="Completed full-volume hospital dispatches"
                trend-tone="up"
                icon="check-circle"
                accent="emerald"
            />

            <x-bdms.stat-card
                label="Total Units Requested"
                :value="$summary['totalUnitsRequested']"
                trend="Cumulative units across logged orders"
                trend-tone="neutral"
                icon="droplet"
                accent="sky"
            />

            <x-bdms.stat-card
                label="Total Units Fulfilled"
                :value="$summary['totalUnitsFulfilled']"
                trend="Dispatched units recorded across orders"
                trend-tone="up"
                icon="building-hospital"
                accent="emerald"
            />
        </section>

        {{-- Requests Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Hospital Blood Requests Queue
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered">{{ count($requests) }}</span> of {{ count($requests) }} blood requests
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                x-show="hasActiveFilters"
                                x-cloak
                                @click="resetFilters()"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                            >
                                <x-icons.icon name="x-mark" class="size-3.5" />
                                <span>Clear all filters</span>
                            </button>

                            <label for="requests-per-page" class="sr-only">Rows per page</label>
                            <select
                                id="requests-per-page"
                                x-model.number="perPage"
                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            >
                                <option value="5">5 / page</option>
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                            </select>
                        </div>
                    </div>

                    {{-- Search & Filter Controls --}}
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <div class="xl:col-span-2">
                            <label for="request-search" class="sr-only">Search by request ID or hospital name</label>
                            <x-ui.input
                                id="request-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                placeholder="Search Request ID or hospital name..."
                                x-model.debounce.150ms="search"
                            />
                        </div>

                        <div>
                            <label for="filter-request-blood-group" class="sr-only">Filter by blood group</label>
                            <select
                                id="filter-request-blood-group"
                                x-model="bloodGroupFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Blood Groups</option>
                                @foreach ($bloodGroups as $group)
                                    <option value="{{ $group }}">{{ $group }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="filter-request-priority" class="sr-only">Filter by priority</label>
                            <select
                                id="filter-request-priority"
                                x-model="priorityFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Priorities</option>
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority }}">{{ $priority }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="filter-request-status" class="sr-only">Filter by status</label>
                            <select
                                id="filter-request-status"
                                x-model="statusFilter"
                                class="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="all">All Statuses</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ $status }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="requests-date-from" class="sr-only">From request date</label>
                                <input
                                    id="requests-date-from"
                                    type="date"
                                    x-model="dateFrom"
                                    title="From request date"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                            <div>
                                <label for="requests-date-to" class="sr-only">To request date</label>
                                <input
                                    id="requests-date-to"
                                    type="date"
                                    x-model="dateTo"
                                    title="To request date"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2.5 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Request ID</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Hospital Name</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Blood Group</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Requested Units</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Fulfilled Units</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Request Date</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Required Date</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Priority</th>
                            <th scope="col" class="px-5 py-3.5 whitespace-nowrap">Request Status</th>
                            <th scope="col" class="px-5 py-3.5 text-right whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @foreach ($requests as $index => $item)
                            @php
                                $priorityVariant = match ($item['priority']) {
                                    'Critical' => 'danger',
                                    'High' => 'warning',
                                    'Normal' => 'info',
                                    default => 'neutral',
                                };

                                $statusVariant = match ($item['status']) {
                                    'Fulfilled' => 'success',
                                    'Approved' => 'info',
                                    'Partially Fulfilled' => 'crimson',
                                    'Rejected' => 'danger',
                                    'Cancelled' => 'neutral',
                                    default => 'warning',
                                };
                            @endphp

                            <tr
                                x-show="isRowVisible({{ $index }})"
                                class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40"
                            >
                                <td class="px-5 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200">
                                    <a
                                        href="{{ route('requests.show', $item['id']) }}"
                                        class="hover:text-crimson-600 dark:hover:text-crimson-400"
                                    >
                                        {{ $item['id'] }}
                                    </a>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div>
                                        <a
                                            href="{{ route('requests.show', $item['id']) }}"
                                            class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                        >
                                            {{ $item['hospitalName'] }}
                                        </a>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $item['contactPerson'] }}
                                        </p>
                                    </div>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <x-bdms.blood-group-badge :group="$item['bloodGroup']" size="sm" />
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap font-semibold text-slate-800 dark:text-slate-200">
                                    {{ $item['requestedUnits'] }} units
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-300">
                                    {{ $item['fulfilledUnits'] }} / {{ $item['requestedUnits'] }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ $item['requestDateFormatted'] }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                    {{ $item['requiredDateFormatted'] }}
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$priorityVariant" :dot="true">
                                        {{ $item['priority'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-5 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$statusVariant">
                                        {{ $item['status'] }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <x-ui.button variant="ghost" size="sm" :href="route('requests.show', $item['id'])">
                                            <x-icons.icon name="eye" class="size-4" />
                                            <span>View</span>
                                        </x-ui.button>

                                        <x-ui.button variant="outline" size="sm" :href="route('requests.edit', $item['id'])">
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
                    <x-icons.icon name="clipboard-list" class="size-6" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-slate-900 dark:text-white">
                    No matching blood requests found
                </h3>
                <p class="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">
                    Adjust your search text, blood group, priority, status, or request date range filter to locate orders.
                </p>
                <button
                    type="button"
                    @click="resetFilters()"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-crimson-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-crimson-700 cursor-pointer"
                >
                    <x-icons.icon name="x-mark" class="size-4" />
                    <span>Clear all filters</span>
                </button>
            </div>

            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Showing <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingFrom"></span>
                        to <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="showingTo"></span>
                        of <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="totalFiltered"></span> requests
                    </p>

                    <nav aria-label="Blood requests pagination" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            @click="prevPage()"
                            :disabled="safePage <= 1"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
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
                                class="inline-flex size-8 items-center justify-center rounded-lg border text-xs font-semibold transition cursor-pointer"
                            ></button>
                        </template>

                        <button
                            type="button"
                            @click="nextPage()"
                            :disabled="safePage >= totalPages"
                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
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

