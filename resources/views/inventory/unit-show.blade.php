<x-app-layout page-title="Blood Unit Details" :active-role="$activeRole">
    <x-slot:header>
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <span class="font-mono text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $unit->unit_code }}
                    </span>
                    <x-bdms.blood-group-badge :group="$unit->blood_group" size="md" />
                    @php
                        $statusBadgeClass = match ($unit->unit_status) {
                            'Available' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                            'Reserved' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                            'Dispatched' => 'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-950/60 dark:text-purple-300 dark:ring-purple-800/70',
                            'Expired' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                            'Discarded' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
                            'Quarantined' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                            default => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                        };
                    @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $statusBadgeClass }}">
                        {{ $unit->unit_status }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Complete serialized unit lifecycle, screening release provenance, allocations, and audit history.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button variant="outline" size="sm" :href="route('inventory.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Inventory</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Success / Error Feedback --}}
        @if (session('success'))
            <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800/70 dark:bg-emerald-950/60 dark:text-emerald-200">
                <x-icons.icon name="check-circle" class="size-5 mt-0.5 shrink-0" />
                <p class="font-medium">{{ session('success') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-200">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Unit Specifications Grid --}}
        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Bag & Product Details --}}
            <x-ui.card>
                <x-slot:header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Blood Bag Specifications
                    </h3>
                </x-slot:header>
                <dl class="divide-y divide-slate-100 text-xs dark:divide-slate-800">
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Blood Component</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->bloodComponent?->name }} ({{ $unit->bloodComponent?->code }})
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Volume</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->volume_ml }} mL
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Storage Location</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->storage_location ?? 'Vault Unassigned' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Storage Requirements</dt>
                        <dd class="font-medium text-slate-900 dark:text-white text-right max-w-48">
                            {{ $unit->bloodComponent?->storage_requirements ?? 'Monitored Cold Vault' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Provenance & Screening Release --}}
            <x-ui.card>
                <x-slot:header>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Intake &amp; Screening Provenance
                    </h3>
                </x-slot:header>
                <dl class="divide-y divide-slate-100 text-xs dark:divide-slate-800">
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Donation Code</dt>
                        <dd class="font-mono font-medium text-slate-900 dark:text-white">
                            {{ $unit->donation?->donation_code ?? 'N/A' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Collected At</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->collected_at?->format('M d, Y • H:i') ?? 'N/A' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Collection Center</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->donation?->center?->name ?? 'Regional Collection Facility' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Released by Officer</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->releasedBy?->name ?? 'System Authorized' }}
                        </dd>
                    </div>
                    <div class="flex justify-between py-2.5">
                        <dt class="text-slate-500 dark:text-slate-400">Released to Stock</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">
                            {{ $unit->released_at?->format('M d, Y • H:i') ?? 'Pending Release' }}
                        </dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Expiry Boundary & Actions --}}
            <x-ui.card class="flex flex-col justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Expiration &amp; Actions
                    </h3>
                    <div class="mt-3 space-y-2 text-xs">
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400">Expiration Date</span>
                            <span class="font-semibold text-slate-900 dark:text-white">
                                {{ $unit->expires_at?->format('M d, Y • H:i') }}
                            </span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400">Status Boundary</span>
                            @if ($unit->expires_at && $unit->expires_at->isPast())
                                <span class="font-semibold text-rose-600 dark:text-rose-400">Expired</span>
                            @elseif ($unit->expires_at && $unit->expires_at->lte(now()->addDays(7)))
                                <span class="font-semibold text-amber-600 dark:text-amber-400">Expiring in {{ (int) ceil(now()->diffInHours($unit->expires_at) / 24) }} days</span>
                            @else
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400">Within Valid Window</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if (auth()->user()?->hasPermission('inventory.adjust'))
                    <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800 space-y-2">
                        @if ($unit->unit_status === 'Available' && $unit->expires_at && $unit->expires_at->isPast())
                            <form method="POST" action="{{ route('inventory.units.expire', $unit->id) }}">
                                @csrf
                                <button
                                    type="submit"
                                    class="w-full inline-flex items-center justify-center gap-1.5 rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white shadow-xs hover:bg-amber-700 transition cursor-pointer"
                                >
                                    <x-icons.icon name="clock" class="size-4" />
                                    <span>Mark as Expired</span>
                                </button>
                            </form>
                        @endif

                        @if (in_array($unit->unit_status, ['Available', 'Reserved', 'Quarantined']))
                            <form
                                method="POST"
                                action="{{ route('inventory.units.discard', $unit->id) }}"
                                onsubmit="return confirm('Are you sure you want to discard blood unit {{ $unit->unit_code }}? This action is permanent and auditable.');"
                                class="space-y-2"
                            >
                                @csrf
                                <input
                                    type="text"
                                    name="reason"
                                    placeholder="Discard reason (min 3 chars)..."
                                    required
                                    minlength="3"
                                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900 shadow-2xs focus:border-crimson-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                                <button
                                    type="submit"
                                    class="w-full inline-flex items-center justify-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100 transition dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-300 cursor-pointer"
                                >
                                    <x-icons.icon name="trash" class="size-4" />
                                    <span>Discard Unit</span>
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </x-ui.card>
        </div>

        {{-- Linked Allocations (Blood Requests) --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            Hospital Request Allocations
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Active holds, releases, or dispatch fulfillment linkages for this blood bag.
                        </p>
                    </div>
                    <x-ui.badge variant="neutral">{{ $unit->allocations->count() }} records</x-ui.badge>
                </div>
            </x-slot:header>

            @if ($unit->allocations->isEmpty())
                <div class="px-6 py-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    No hospital request allocations have referenced this blood unit.
                </div>
            @else
                <div class="divide-y divide-slate-100 dark:divide-slate-800 text-xs">
                    @foreach ($unit->allocations as $alloc)
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between px-6 py-3 gap-2">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-semibold text-slate-900 dark:text-white">
                                        {{ $alloc->allocation_code }}
                                    </span>
                                    <span class="rounded-full px-2 py-0.5 text-2xs font-semibold ring-1 ring-inset {{ match ($alloc->allocation_status) {
                                        'Reserved' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300',
                                        'Dispatched' => 'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-950/60 dark:text-purple-300',
                                        'Released' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300',
                                        default => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300',
                                    } }}">
                                        {{ $alloc->allocation_status }}
                                    </span>
                                </div>
                                <p class="text-slate-500 dark:text-slate-400">
                                    Request: <strong class="text-slate-700 dark:text-slate-200">{{ $alloc->bloodRequest?->request_code }}</strong>
                                    &middot; Hospital: {{ $alloc->bloodRequest?->hospital?->name }}
                                </p>
                            </div>
                            <span class="text-slate-500 dark:text-slate-400">
                                {{ $alloc->allocated_at?->format('M d, Y • H:i') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        {{-- Serialized Unit Audit Ledger History --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            Unit Transaction Ledger
                        </h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Immutable append-only movements and state transitions recorded for this blood bag.
                        </p>
                    </div>
                    <x-ui.badge variant="neutral">{{ $unit->transactions->count() }} transactions</x-ui.badge>
                </div>
            </x-slot:header>

            @if ($unit->transactions->isEmpty())
                <div class="px-6 py-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    No ledger transactions recorded for this blood unit.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs dark:divide-slate-800">
                        <thead class="bg-slate-50/70 text-left font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3">Txn Code</th>
                                <th class="px-5 py-3">Operation</th>
                                <th class="px-5 py-3">Delta</th>
                                <th class="px-5 py-3">Description</th>
                                <th class="px-5 py-3">Actor</th>
                                <th class="px-5 py-3 text-right">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            @foreach ($unit->transactions as $txn)
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                    <td class="whitespace-nowrap px-5 py-3 font-mono font-medium text-slate-900 dark:text-white">
                                        {{ $txn->transaction_code }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold ring-1 ring-inset {{ match ($txn->operation_type) {
                                            'Stock In' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300',
                                            'Reserve' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300',
                                            'Release Reservation' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300',
                                            'Dispatch' => 'bg-purple-50 text-purple-700 ring-purple-200 dark:bg-purple-950/60 dark:text-purple-300',
                                            'Discarded', 'Removed' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300',
                                            'Expired' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300',
                                            default => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300',
                                        } }}">
                                            {{ $txn->operation_type }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 font-semibold tabular-nums {{ $txn->units_delta > 0 ? 'text-emerald-600' : ($txn->units_delta < 0 ? 'text-rose-600' : 'text-slate-600') }}">
                                        {{ $txn->units_delta > 0 ? '+'.$txn->units_delta : $txn->units_delta }}
                                    </td>
                                    <td class="px-5 py-3 text-slate-600 dark:text-slate-300 max-w-sm">
                                        {{ $txn->description }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 text-slate-800 dark:text-slate-200">
                                        {{ $txn->actor_name }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 text-right text-slate-500 dark:text-slate-400">
                                        {{ $txn->occurred_at?->format('M d, Y • H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>

