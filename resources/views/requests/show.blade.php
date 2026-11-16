@php
    $priorityVariant = match ($bloodRequest['priority']) {
        'Critical' => 'danger',
        'High' => 'warning',
        'Normal' => 'info',
        default => 'neutral',
    };

    $initialPercent = $bloodRequest['requestedUnits'] > 0
        ? (int) min(100, round(($bloodRequest['fulfilledUnits'] / $bloodRequest['requestedUnits']) * 100))
        : 0;

    $isTerminal = in_array($bloodRequest['status'], ['Rejected', 'Cancelled', 'Fulfilled'], true);
    $initialGroupStock = (int) ($sampleInventory[$bloodRequest['bloodGroup']] ?? 0);
@endphp

<x-app-layout :title="'Blood Request · ' . $bloodRequest['id']" page-title="Request Details">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('requests.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Blood Requests
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="font-mono text-slate-700 dark:text-slate-200">{{ $bloodRequest['id'] }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $bloodRequest['id'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$bloodRequest['bloodGroup']" size="md" />
                    <x-ui.badge :variant="$priorityVariant" :dot="true">
                        Priority: {{ $bloodRequest['priority'] }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ $bloodRequest['hospitalName'] }} &middot; Requested {{ $bloodRequest['requestDateFormatted'] }} &middot; Required by {{ $bloodRequest['requiredDateFormatted'] }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('requests.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Requests</span>
                </x-ui.button>

                @can('update', $bloodRequestRecord)
                    @if (! $isTerminal)
                        <x-ui.button variant="primary" size="md" :href="route('requests.edit', $bloodRequest['id'])">
                            <x-icons.icon name="pencil" class="size-4" />
                            <span>Edit Request</span>
                        </x-ui.button>
                    @endif
                @endcan

                @can('cancel', $bloodRequestRecord)
                    <button
                        type="button"
                        @click="$dispatch('open-modal', 'cancel-request-modal')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 cursor-pointer"
                    >
                        <x-icons.icon name="no-symbol" class="size-3.5 text-slate-500" />
                        <span>Cancel Request</span>
                    </button>
                @endcan
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Top Fulfillment KPI Strip (5 cards) --}}
        <section aria-label="Request fulfillment summary" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Current Status
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ match ($bloodRequest['status']) {
                        'Fulfilled' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                        'Approved' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                        'Partially Fulfilled' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                        'Rejected' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
                        'Cancelled' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                        default => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                    } }}">
                        {{ $bloodRequest['status'] }}
                    </span>
                    <x-icons.icon name="clipboard-list" class="size-5 text-slate-400 dark:text-slate-500" />
                </div>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Priority: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $bloodRequest['priority'] }}</span>
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Requested Units
                </p>
                <div class="mt-3 flex items-center justify-between">
                    <p class="text-2xl font-bold text-slate-900 dark:text-white">
                        {{ $bloodRequest['requestedUnits'] }} units
                    </p>
                    <x-bdms.blood-group-badge :group="$bloodRequest['bloodGroup']" size="md" />
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Required by {{ $bloodRequest['requiredDateFormatted'] }}
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Reserved Units
                </p>
                <p class="mt-3 text-2xl font-bold text-sky-600 dark:text-sky-400">
                    {{ $bloodRequest['reservedUnits'] }} units
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Held in inventory reserve
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Fulfilled Units
                </p>
                <p class="mt-3 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    {{ $bloodRequest['fulfilledUnits'] }} units
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Dispatched toward order
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Remaining Units
                </p>
                <p class="mt-3 text-2xl font-bold text-slate-900 dark:text-white">
                    {{ $bloodRequest['remainingUnits'] }} units
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Requested minus fulfilled
                </p>
            </x-ui.card>
        </section>

        {{-- Fulfillment Progress Bar Card --}}
        <x-ui.card>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        Fulfillment Progress
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $bloodRequest['fulfilledUnits'] }}</span>
                        of {{ $bloodRequest['requestedUnits'] }} units fulfilled &middot;
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $bloodRequest['remainingUnits'] }}</span> remaining
                    </p>
                </div>

                <span class="font-mono text-sm font-bold text-slate-900 dark:text-white">
                    {{ $initialPercent }}%
                </span>
            </div>

            <div class="mt-3 h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div
                    class="h-full rounded-full transition-all duration-300 {{ $bloodRequest['status'] === 'Fulfilled' ? 'bg-emerald-500' : ($isTerminal ? 'bg-slate-400 dark:bg-slate-600' : 'bg-crimson-600') }}"
                    style="width: {{ $initialPercent }}%"
                ></div>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Left Column: Hospital Information & Clinical Notes --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Hospital Information
                        </h2>
                    </x-slot:header>

                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">Hospital</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $bloodRequest['hospitalName'] }}</dd>
                        </div>

                        <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">Contact Officer</dt>
                            <dd class="text-slate-800 dark:text-slate-200">{{ $bloodRequest['contactPerson'] }}</dd>
                        </div>

                        <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">Contact Phone</dt>
                            <dd class="font-mono text-slate-800 dark:text-slate-200">{{ $bloodRequest['contactNumber'] }}</dd>
                        </div>

                        <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                            <dd class="font-mono text-xs text-slate-800 dark:text-slate-200">{{ $bloodRequest['email'] }}</dd>
                        </div>

                        <div class="flex justify-between border-b border-slate-100 pb-2 dark:border-slate-800">
                            <dt class="text-slate-500 dark:text-slate-400">Requester Account</dt>
                            <dd class="text-right text-xs text-slate-700 dark:text-slate-300">
                                {{ $bloodRequest['requesterName'] }}
                            </dd>
                        </div>

                        <div class="flex justify-between pt-1">
                            <dt class="text-slate-500 dark:text-slate-400">Ward / Address</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $bloodRequest['hospitalAddress'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Clinical Request Details
                        </h2>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <dl class="grid grid-cols-2 gap-3 border-b border-slate-100 pb-4 dark:border-slate-800">
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Request Date</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $bloodRequest['requestDateFormatted'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Required Date</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $bloodRequest['requiredDateFormatted'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Blood Group</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $bloodRequest['bloodGroup'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-slate-500 dark:text-slate-400">Component</dt>
                                <dd class="mt-0.5 font-medium text-slate-900 dark:text-white">{{ $bloodRequest['componentName'] ?? 'Whole Blood / RBC' }}</dd>
                            </div>
                        </dl>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                Clinical Reason
                            </p>
                            <p class="mt-1 leading-relaxed text-slate-800 dark:text-slate-200">
                                {{ $bloodRequest['requestReason'] }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Additional Notes</p>
                            <p class="mt-1 leading-relaxed text-slate-700 dark:text-slate-300">
                                {{ $bloodRequest['additionalNotes'] ?: 'No additional notes recorded.' }}
                            </p>
                        </div>

                        @if ($bloodRequest['reviewedBy'] || $bloodRequest['reviewedAt'])
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-800/50">
                                <p class="font-semibold text-slate-700 dark:text-slate-200">Triage Review</p>
                                <p class="mt-0.5 text-slate-600 dark:text-slate-400">
                                    Reviewed by {{ $bloodRequest['reviewedBy'] ?? 'Staff' }} on {{ $bloodRequest['reviewedAt'] ?? '—' }}
                                </p>
                                @if ($bloodRequest['rejectionReason'])
                                    <p class="mt-1 font-medium text-rose-600 dark:text-rose-400">
                                        Rejection Reason: {{ $bloodRequest['rejectionReason'] }}
                                    </p>
                                @endif
                            </div>
                        @endif

                        @if ($bloodRequest['cancelledBy'] || $bloodRequest['cancelledAt'])
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-800 dark:bg-slate-800/50">
                                <p class="font-semibold text-slate-700 dark:text-slate-200">Cancellation Record</p>
                                <p class="mt-0.5 text-slate-600 dark:text-slate-400">
                                    Cancelled by {{ $bloodRequest['cancelledBy'] ?? 'Requester' }} on {{ $bloodRequest['cancelledAt'] ?? '—' }}
                                </p>
                                @if ($bloodRequest['cancellationReason'])
                                    <p class="mt-1 font-medium text-slate-600 dark:text-slate-300">
                                        Reason: {{ $bloodRequest['cancellationReason'] }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            </div>

            {{-- Right Column: Fulfillment Workflow + Dispatches & Timeline --}}
            <div class="space-y-6 lg:col-span-2">
                {{-- Fulfillment Actions Card --}}
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Fulfillment Workflow
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Triage, reserve, release, and dispatch authorized blood units.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                @can('review', $bloodRequestRecord)
                                    @if ($bloodRequest['status'] === 'Pending')
                                        <button
                                            type="button"
                                            @click="$dispatch('open-modal', 'approve-request-modal')"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-emerald-700 cursor-pointer"
                                        >
                                            <x-icons.icon name="check" class="size-3.5" />
                                            <span>Approve Request</span>
                                        </button>

                                        <button
                                            type="button"
                                            @click="$dispatch('open-modal', 'reject-request-modal')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20 cursor-pointer"
                                        >
                                            <x-icons.icon name="x-mark" class="size-3.5" />
                                            <span>Reject Request</span>
                                        </button>
                                    @endif
                                @endcan
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="space-y-5">
                        {{-- Terminal Lock Alert --}}
                        @if ($isTerminal)
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-200">
                                <div class="flex items-start gap-3">
                                    <x-icons.icon name="lock-closed" class="mt-0.5 size-5 shrink-0 text-slate-500 dark:text-slate-400" />
                                    <div>
                                        <p class="font-semibold">Fulfillment Locked</p>
                                        <p class="mt-0.5 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                            Rejected and Cancelled requests cannot receive additional fulfillment or status modifications.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @elseif ($bloodRequest['status'] === 'Pending')
                            @if ($initialGroupStock < $bloodRequest['remainingUnits'])
                                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                    <div class="flex items-start gap-3">
                                        <x-icons.icon name="exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                                        <div>
                                            <p class="font-semibold">Insufficient sample inventory warning</p>
                                            <p class="mt-0.5 text-xs leading-relaxed">
                                                Selected sample stock for {{ $bloodRequest['bloodGroup'] }} has {{ $initialGroupStock }} units available, while {{ $bloodRequest['remainingUnits'] }} remaining units are needed.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                <div class="flex items-start gap-3">
                                    <x-icons.icon name="clock" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                                    <div>
                                        <p class="font-semibold">Awaiting Triage Review</p>
                                        <p class="mt-0.5 text-xs leading-relaxed">
                                            This request requires clinical triage review by blood bank staff before units can be reserved or dispatched.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Active fulfillment actions for Approved / Partially Fulfilled --}}
                            @can('dispatch', $bloodRequestRecord)
                                <form
                                    method="POST"
                                    action="{{ route('requests.dispatch', $bloodRequestRecord->id) }}"
                                    class="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                                >
                                    @csrf
                                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 dark:border-slate-800">
                                        <div class="flex items-center gap-2">
                                            <x-icons.icon name="truck" class="size-4 text-crimson-600 dark:text-crimson-400" />
                                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                                Record Fulfilled Units
                                            </h3>
                                        </div>
                                        <span class="text-xs text-slate-500 dark:text-slate-400">
                                            Remaining allowed: <strong class="text-slate-800 dark:text-slate-200">{{ $bloodRequest['remainingUnits'] }}</strong> unit(s)
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="dispatch-blood-group" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                                Dispatched Blood Group <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                            </label>
                                            <select
                                                id="dispatch-blood-group"
                                                name="dispatched_blood_group"
                                                class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            >
                                                @foreach ($bloodGroups as $group)
                                                    <option value="{{ $group }}" @selected($group === $bloodRequest['bloodGroup'])>
                                                        {{ $group }} ({{ $sampleInventory[$group] ?? 0 }} units in stock)
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div>
                                            <label for="dispatch-units" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                                Units to Dispatch <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                            </label>
                                            <input
                                                id="dispatch-units"
                                                name="quantity"
                                                type="number"
                                                min="1"
                                                max="{{ max(1, $bloodRequest['remainingUnits']) }}"
                                                step="1"
                                                value="{{ min(1, max(1, $bloodRequest['remainingUnits'])) }}"
                                                required
                                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                        </div>

                                        <div>
                                            <label for="dispatch-date" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                                Dispatch Date
                                            </label>
                                            <input
                                                id="dispatch-date"
                                                name="dispatch_date"
                                                type="date"
                                                value="{{ now()->format('Y-m-d') }}"
                                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                        </div>

                                        <div>
                                            <label for="dispatch-courier" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                                Courier / Transport Reference
                                            </label>
                                            <input
                                                id="dispatch-courier"
                                                name="courier_reference"
                                                type="text"
                                                placeholder="e.g. Courier TR-208 / Cold Box #4"
                                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label for="dispatch-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                                Dispatch Notes
                                            </label>
                                            <textarea
                                                id="dispatch-notes"
                                                name="notes"
                                                rows="2"
                                                placeholder="Seal number, cold-chain temperature verification, or receiving hospital staff..."
                                                class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            ></textarea>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-end gap-3 pt-2">
                                        <x-ui.button variant="primary" size="md" type="submit">
                                            <x-icons.icon name="check" class="size-4" />
                                            <span>Record Dispatch</span>
                                        </x-ui.button>
                                    </div>
                                </form>
                            @endcan

                            @can('allocate', $bloodRequestRecord)
                                <div class="grid grid-cols-1 gap-4 pt-2 sm:grid-cols-2">
                                    {{-- Reserve Units Form --}}
                                    <form
                                        method="POST"
                                        action="{{ route('requests.reserve', $bloodRequestRecord->id) }}"
                                        class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                                    >
                                        @csrf
                                        <div class="flex items-center gap-2">
                                            <x-icons.icon name="archive-box" class="size-4 text-sky-600 dark:text-sky-400" />
                                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">
                                                Place Reserve Hold
                                            </h4>
                                        </div>
                                        <div>
                                            <label for="reserve-quantity" class="block text-xs font-medium text-slate-700 dark:text-slate-200">
                                                Units to Hold <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                            </label>
                                            <input
                                                id="reserve-quantity"
                                                name="quantity"
                                                type="number"
                                                min="1"
                                                max="{{ max(1, $bloodRequest['remainingUnits']) }}"
                                                value="1"
                                                required
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-sky-700 cursor-pointer"
                                        >
                                            <x-icons.icon name="archive-box" class="size-3.5" />
                                            <span>Reserve Units</span>
                                        </button>
                                    </form>

                                    {{-- Release Holds Form --}}
                                    <form
                                        method="POST"
                                        action="{{ route('requests.release', $bloodRequestRecord->id) }}"
                                        class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                                    >
                                        @csrf
                                        <div class="flex items-center gap-2">
                                            <x-icons.icon name="arrow-path" class="size-4 text-amber-600 dark:text-amber-400" />
                                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">
                                                Release Reserved Holds
                                            </h4>
                                        </div>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            Release active reserved units ({{ $bloodRequest['reservedUnits'] }} held) back to available inventory.
                                        </p>
                                        <div>
                                            <input
                                                name="reason"
                                                type="text"
                                                placeholder="Release reason (min 5 chars)..."
                                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            @disabled($bloodRequest['reservedUnits'] < 1)
                                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/20 cursor-pointer"
                                        >
                                            <x-icons.icon name="arrow-path" class="size-3.5" />
                                            <span>Release Reserved Holds</span>
                                        </button>
                                    </form>
                                </div>
                            @endcan
                        @endif
                    </div>
                </x-ui.card>

                {{-- Dispatched Batches Card --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Dispatched Batches
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Recorded physical cold-chain deliveries fulfilling this order.
                                </p>
                            </div>

                            <x-ui.badge variant="neutral">
                                {{ count($bloodRequest['fulfillments']) }} batches
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    @if (empty($bloodRequest['fulfillments']))
                        <div class="px-6 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                            No dispatch batches recorded yet.
                        </div>
                    @else
                        <div class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($bloodRequest['fulfillments'] as $fulfillment)
                                <div class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                                {{ $fulfillment['code'] }}
                                            </span>
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70">
                                                {{ $fulfillment['units'] }} unit(s) &middot; {{ $fulfillment['group'] }}
                                            </span>
                                            @if ($fulfillment['courier'])
                                                <span class="text-xs text-slate-500 dark:text-slate-400">
                                                    Courier: <strong class="text-slate-700 dark:text-slate-200">{{ $fulfillment['courier'] }}</strong>
                                                </span>
                                            @endif
                                        </div>

                                        @if ($fulfillment['notes'])
                                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                                {{ $fulfillment['notes'] }}
                                            </p>
                                        @endif

                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            Dispatched by <span class="font-medium text-slate-700 dark:text-slate-200">{{ $fulfillment['dispatchedBy'] }}</span>
                                        </p>
                                    </div>

                                    <span class="shrink-0 text-xs font-medium text-slate-500 dark:text-slate-400">
                                        {{ $fulfillment['date'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>

                {{-- Status & Fulfillment History Card --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Status &amp; Fulfillment History
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Chronological audit log of request approvals, dispatches, and transitions.
                                </p>
                            </div>

                            <x-ui.badge variant="neutral">
                                {{ count($bloodRequest['history']) }} events
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    @if (empty($bloodRequest['history']))
                        <div class="px-6 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                            No status history recorded yet.
                        </div>
                    @else
                        <div class="divide-y divide-slate-200 dark:divide-slate-800">
                            @foreach ($bloodRequest['history'] as $entry)
                                @php
                                    $histBadgeClass = match ($entry['status']) {
                                        'Fulfilled' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                                        'Approved' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                                        'Partially Fulfilled' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                                        'Rejected' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
                                        'Cancelled' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                                        default => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                                    };
                                @endphp
                                <div class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-900 dark:text-white">
                                                {{ $entry['event'] }}
                                            </span>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $histBadgeClass }}">
                                                {{ $entry['status'] }}
                                            </span>
                                            @if ($entry['units'] > 0)
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70">
                                                    +{{ $entry['units'] }} units
                                                </span>
                                            @endif
                                        </div>

                                        @if ($entry['notes'])
                                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                                {{ $entry['notes'] }}
                                            </p>
                                        @endif

                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            Updated by <span class="font-medium text-slate-700 dark:text-slate-200">{{ $entry['actor'] }}</span>
                                        </p>
                                    </div>

                                    <span class="shrink-0 text-xs font-medium text-slate-500 dark:text-slate-400">
                                        {{ $entry['date'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </div>

        {{-- Approve Request Modal --}}
        @can('review', $bloodRequestRecord)
            <x-ui.modal name="approve-request-modal" title="Approve Blood Request" max-width="md">
                <form method="POST" action="{{ route('requests.approve', $bloodRequestRecord->id) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Authorize triage approval for <strong>{{ $bloodRequest['id'] }}</strong> ({{ $bloodRequest['hospitalName'] }}). Units can then be reserved or dispatched.
                    </p>

                    <div>
                        <label for="approve-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Approval Notes <span class="text-xs text-slate-400 font-normal">(Optional)</span>
                        </label>
                        <textarea
                            id="approve-notes"
                            name="notes"
                            rows="3"
                            placeholder="Clinical clearance notes, special crossmatching requirements, or delivery schedule..."
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'approve-request-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700 cursor-pointer"
                        >
                            Confirm Approval
                        </button>
                    </div>
                </form>
            </x-ui.modal>

            {{-- Reject Request Modal --}}
            <x-ui.modal name="reject-request-modal" title="Reject Blood Request" max-width="md">
                <form method="POST" action="{{ route('requests.reject', $bloodRequestRecord->id) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Provide a clinical reason for rejecting <strong>{{ $bloodRequest['id'] }}</strong> ({{ $bloodRequest['hospitalName'] }}). Rejected requests are permanently closed.
                    </p>

                    <div>
                        <label for="rejection-reason" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Rejection Reason <span class="text-crimson-600 dark:text-crimson-400">*</span>
                        </label>
                        <textarea
                            id="rejection-reason"
                            name="rejection_reason"
                            rows="3"
                            required
                            minlength="5"
                            placeholder="e.g. Duplicate order, incompatible protocol, or insufficient regional inventory..."
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'reject-request-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-rose-700 cursor-pointer"
                        >
                            Confirm Rejection
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan

        {{-- Cancel Request Modal --}}
        @can('cancel', $bloodRequestRecord)
            <x-ui.modal name="cancel-request-modal" title="Cancel Blood Request" max-width="md">
                <form method="POST" action="{{ route('requests.cancel', $bloodRequestRecord->id) }}" class="space-y-4">
                    @csrf
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Cancel blood request <strong>{{ $bloodRequest['id'] }}</strong>. Any reserved inventory holds will be released back to available stock.
                    </p>

                    <div>
                        <label for="cancellation-reason" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Cancellation Reason <span class="text-crimson-600 dark:text-crimson-400">*</span>
                        </label>
                        <textarea
                            id="cancellation-reason"
                            name="cancellation_reason"
                            rows="3"
                            required
                            minlength="5"
                            placeholder="e.g. Patient discharged, surgical procedure postponed, or requirement satisfied..."
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                        ></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'cancel-request-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            Keep Request
                        </button>
                        <button
                            type="submit"
                            class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 cursor-pointer"
                        >
                            Confirm Cancellation
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        @endcan
    </div>
</x-app-layout>
