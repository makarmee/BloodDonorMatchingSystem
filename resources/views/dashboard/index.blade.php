<x-app-layout :page-title="$portalData['pageTitle']" :active-role="$activeRole">
    <x-slot:header>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ $portalData['pageTitle'] }}
                </h2>
            </div>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                {{ $portalData['subtitle'] }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($activeRole === 'hospital')
                <x-ui.button variant="primary" size="sm" x-data @click="$dispatch('open-modal', 'quick-action-preview')">
                    <x-icons.icon name="plus" class="size-4" />
                    New Blood Request
                </x-ui.button>
            @elseif ($activeRole === 'donor')
                <x-ui.button variant="primary" size="sm" x-data @click="$dispatch('open-modal', 'quick-action-preview')">
                    <x-icons.icon name="calendar" class="size-4" />
                    Book Appointment
                </x-ui.button>
            @else
                <x-ui.button variant="outline" size="sm" :href="route('home')">
                    Public Stock View
                </x-ui.button>
                <x-ui.button variant="primary" size="sm" x-data @click="$dispatch('open-modal', 'quick-action-preview')">
                    <x-icons.icon name="plus" class="size-4" />
                    Record Donation
                </x-ui.button>
            @endif
        </div>
    </x-slot:header>

    {{-- KPI Stat Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($portalData['kpis'] as $kpi)
            <x-bdms.stat-card
                :label="$kpi['label']"
                :value="$kpi['value']"
                :trend="$kpi['trend']"
                :trend-tone="$kpi['trendTone']"
                :icon="$kpi['icon']"
                :accent="$kpi['accent']"
            />
        @endforeach
    </div>

    {{-- Main Two-Column Grid --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-12">
        {{-- Recent Activity / Requests Table --}}
        <div class="lg:col-span-8">
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                {{ $activeRole === 'donor' ? 'Recent Donations & Visits' : 'Recent Blood Requests' }}
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Sample records rendered through reusable Blade components
                            </p>
                        </div>
                        <x-ui.badge variant="neutral">{{ count($portalData['recentActivity']) }} items</x-ui.badge>
                    </div>
                </x-slot:header>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3">Ref</th>
                                <th scope="col" class="px-5 py-3">{{ $activeRole === 'donor' ? 'Center / Event' : 'Hospital / Ward' }}</th>
                                <th scope="col" class="px-5 py-3">Group</th>
                                <th scope="col" class="px-5 py-3">Quantity</th>
                                <th scope="col" class="px-5 py-3">Status</th>
                                <th scope="col" class="px-5 py-3 text-right">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            @foreach ($portalData['recentActivity'] as $row)
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                    <td class="whitespace-nowrap px-5 py-3.5 font-mono text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        {{ $row['reference'] }}
                                    </td>
                                    <td class="px-5 py-3.5 font-medium text-slate-900 dark:text-white">
                                        {{ $row['subject'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <x-bdms.blood-group-badge :group="$row['group']" size="sm" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-slate-600 dark:text-slate-300">
                                        {{ $row['quantity'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <x-bdms.urgency-badge :level="$row['status']" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-right text-xs text-slate-500 dark:text-slate-400">
                                        {{ $row['time'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Regional Blood Stock Snapshot --}}
        <div class="lg:col-span-4 space-y-6">
            <x-ui.card>
                <x-slot:header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            Critical Stock Watch
                        </h3>
                        <x-icons.icon name="droplet" class="size-4 text-crimson-600 dark:text-crimson-400" />
                    </div>
                </x-slot:header>

                <div class="space-y-4">
                    @foreach ($portalData['inventorySnapshot'] as $stock)
                        <x-bdms.stock-level-bar
                            :group="$stock['group']"
                            :units="$stock['units']"
                            :capacity="$stock['capacity']"
                        />
                    @endforeach
                </div>

                <x-slot:footer>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Role-scoped access for <span class="font-semibold text-slate-700 dark:text-slate-200">{{ auth()->user()?->name ?? 'Authenticated User' }}</span>.
                    </p>
                </x-slot:footer>
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal name="quick-action-preview" title="Module Action Preview" maxWidth="md">
        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
            This modal demonstrates the reusable <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">&lt;x-ui.modal&gt;</code> component. Dedicated CRUD forms for Inventory, Donors, Donations, and Blood Requests can be built in the next phase.
        </p>
        <div class="mt-5 flex justify-end">
            <x-ui.button variant="primary" size="sm" @click="$dispatch('close-modal', 'quick-action-preview')">
                Got it
            </x-ui.button>
        </div>
    </x-ui.modal>
</x-app-layout>

