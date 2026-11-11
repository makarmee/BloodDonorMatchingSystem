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

    $isInitiallyBlocked = in_array($bloodRequest['status'], ['Rejected', 'Cancelled'], true);
    $initialGroupStock = (int) ($sampleInventory[$bloodRequest['bloodGroup']] ?? 0);
    $isInitiallyInsufficient = ! $isInitiallyBlocked
        && $bloodRequest['status'] !== 'Fulfilled'
        && $initialGroupStock < $bloodRequest['remainingUnits'];

    $fulfillmentPayload = [
        'request' => $bloodRequest,
        'inventoryStock' => $sampleInventory,
    ];
@endphp

<x-app-layout :title="'Blood Request · ' . $bloodRequest['id']" page-title="Request Details" active-role="staff">
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

                <x-ui.button variant="primary" size="md" :href="route('requests.edit', $bloodRequest['id'])">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>Edit Request</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6" x-data="requestFulfillment(@js($fulfillmentPayload))">
        {{-- Reactive Preview Feedback Banner --}}
        <div
            x-show="feedback !== null"
            x-cloak
            role="status"
            :class="feedback?.type === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200'
                : 'border-crimson-200 bg-crimson-50 text-crimson-900 dark:border-crimson-500/30 dark:bg-crimson-500/10 dark:text-crimson-200'"
            class="flex items-start justify-between gap-4 rounded-2xl border p-4 shadow-xs"
        >
            <div class="flex items-start gap-3">
                <span
                    :class="feedback?.type === 'success'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-crimson-600 dark:text-crimson-400'"
                    class="mt-0.5 shrink-0"
                >
                    <template x-if="feedback?.type === 'success'">
                        <x-icons.icon name="check-circle" class="size-5" />
                    </template>
                    <template x-if="feedback?.type !== 'success'">
                        <x-icons.icon name="exclamation-triangle" class="size-5" />
                    </template>
                </span>

                <div>
                    <h3 class="text-sm font-semibold" x-text="feedback?.title"></h3>
                    <p class="mt-0.5 text-sm opacity-90" x-text="feedback?.message"></p>
                </div>
            </div>

            <button
                type="button"
                @click="dismissFeedback()"
                aria-label="Dismiss feedback"
                class="rounded-lg p-1 opacity-70 transition hover:opacity-100 cursor-pointer"
            >
                <x-icons.icon name="x-mark" class="size-4" />
            </button>
        </div>

        {{-- Top Fulfillment KPI Strip --}}
        <section aria-label="Request fulfillment summary" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Current Status
                </p>
                <div class="mt-3 flex items-center justify-between gap-2">
                    <span
                        class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset"
                        :class="statusBadgeClasses(status)"
                        x-text="status"
                    >{{ $bloodRequest['status'] }}</span>
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
                    Fulfilled Units
                </p>
                <p class="mt-3 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    <span x-text="fulfilledUnits">{{ $bloodRequest['fulfilledUnits'] }}</span> units
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Dispatched toward hospital order
                </p>
            </x-ui.card>

            <x-ui.card>
                <p class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400">
                    Remaining Units
                </p>
                <p class="mt-3 text-2xl font-bold text-slate-900 dark:text-white">
                    <span x-text="remainingUnits">{{ $bloodRequest['remainingUnits'] }}</span> units
                </p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Requested minus fulfilled units
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
                        <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="fulfilledUnits">{{ $bloodRequest['fulfilledUnits'] }}</span>
                        of {{ $bloodRequest['requestedUnits'] }} units fulfilled &middot;
                        <span class="font-semibold text-slate-700 dark:text-slate-200" x-text="remainingUnits">{{ $bloodRequest['remainingUnits'] }}</span> remaining
                    </p>
                </div>

                <span class="font-mono text-sm font-bold text-slate-900 dark:text-white" x-text="progressPercent + '%'">
                    {{ $initialPercent }}%
                </span>
            </div>

            <div class="mt-3 h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                <div
                    class="h-full rounded-full transition-all duration-300"
                    :class="status === 'Fulfilled'
                        ? 'bg-emerald-500'
                        : (isTerminalBlocked ? 'bg-slate-400 dark:bg-slate-600' : 'bg-crimson-600')"
                    style="width: {{ $initialPercent }}%"
                    :style="`width: ${progressPercent}%`"
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

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-start justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">Hospital</dt>
                            <dd class="text-right font-semibold text-slate-900 dark:text-white">{{ $bloodRequest['hospitalName'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Contact Person</dt>
                            <dd class="font-medium text-slate-800 dark:text-slate-200">{{ $bloodRequest['contactPerson'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Contact Number</dt>
                            <dd class="text-slate-700 dark:text-slate-200">{{ $bloodRequest['contactNumber'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">Email</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200">{{ $bloodRequest['email'] }}</dd>
                        </div>

                        <div class="flex items-start justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">Address</dt>
                            <dd class="text-right text-slate-700 dark:text-slate-200">{{ $bloodRequest['hospitalAddress'] }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Request Reason &amp; Notes
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
                        </dl>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5 dark:border-slate-800 dark:bg-slate-800/50">
                            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                Clinical Request Reason
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
                    </div>
                </x-ui.card>
            </div>

            {{-- Right Column: Fulfillment Workflow + Status & Fulfillment History --}}
            <div class="space-y-6 lg:col-span-2">
                {{-- Fulfillment Workflow Card --}}
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Fulfillment Workflow
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Approve, reject, or record unit dispatches using read-only sample inventory levels (preview only).
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    x-show="canApprove"
                                    @if (! ($bloodRequest['status'] === 'Pending')) x-cloak @endif
                                    @click="approveRequest()"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-emerald-700 cursor-pointer"
                                >
                                    <x-icons.icon name="check" class="size-3.5" />
                                    <span>Approve Request</span>
                                </button>

                                <button
                                    type="button"
                                    x-show="canReject"
                                    @if ($isInitiallyBlocked || $bloodRequest['status'] === 'Fulfilled') x-cloak @endif
                                    @click="$dispatch('open-modal', 'reject-request-modal')"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:border-rose-500/40 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/20 cursor-pointer"
                                >
                                    <x-icons.icon name="x-mark" class="size-3.5" />
                                    <span>Reject Request</span>
                                </button>
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="space-y-5">
                        {{-- Rule 6 Locked Alert for Rejected or Cancelled Requests --}}
                        <div
                            x-show="isTerminalBlocked"
                            @if (! $isInitiallyBlocked) x-cloak @endif
                            class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200"
                        >
                            <div class="flex items-start gap-3">
                                <x-icons.icon name="lock-closed" class="mt-0.5 size-5 shrink-0 text-rose-600 dark:text-rose-400" />
                                <div>
                                    <p class="font-semibold">Fulfillment Locked</p>
                                    <p class="mt-0.5 text-xs leading-relaxed">
                                        Rejected and Cancelled requests cannot receive additional fulfillment or approval updates.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Completed State Alert --}}
                        <div
                            x-show="status === 'Fulfilled'"
                            @if ($bloodRequest['status'] !== 'Fulfilled') x-cloak @endif
                            class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                        >
                            <div class="flex items-start gap-3">
                                <x-icons.icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <div>
                                    <p class="font-semibold">All Requested Units Fulfilled</p>
                                    <p class="mt-0.5 text-xs leading-relaxed">
                                        100% of requested units ({{ $bloodRequest['requestedUnits'] }}/{{ $bloodRequest['requestedUnits'] }}) have been recorded as fulfilled.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Rule 7 Insufficient Sample Inventory Warning --}}
                        <div
                            x-show="isInventoryInsufficient"
                            @if (! $isInitiallyInsufficient) x-cloak @endif
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                        >
                            <div class="flex items-start gap-3">
                                <x-icons.icon name="exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                                <div>
                                    <p class="font-semibold">Insufficient sample inventory warning</p>
                                    <p class="mt-0.5 text-xs leading-relaxed">
                                        Selected sample stock for <strong x-text="dispatch.bloodGroup">{{ $bloodRequest['bloodGroup'] }}</strong> has
                                        <strong x-text="selectedGroupAvailableStock">{{ $initialGroupStock }}</strong> units available, while
                                        <strong x-text="remainingUnits">{{ $bloodRequest['remainingUnits'] }}</strong> remaining units are needed. Actual Inventory balances are not altered in frontend preview mode.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Record Fulfillment Dispatch Form --}}
                        <form
                            @submit.prevent="recordFulfillment()"
                            x-show="canRecordFulfillment"
                            @if ($isInitiallyBlocked || $bloodRequest['status'] === 'Fulfilled') x-cloak @endif
                            novalidate
                            class="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-slate-800"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                    Record Fulfilled Units
                                </h3>
                                <span class="text-xs text-slate-500 dark:text-slate-400">
                                    Remaining allowed: <strong class="text-slate-800 dark:text-slate-200" x-text="remainingUnits">{{ $bloodRequest['remainingUnits'] }}</strong> unit(s)
                                </span>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="dispatch-blood-group" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Inventory Blood Group <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>
                                    <select
                                        id="dispatch-blood-group"
                                        x-model="dispatch.bloodGroup"
                                        class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                        @foreach ($bloodGroups as $group)
                                            <option value="{{ $group }}">
                                                {{ $group }} ({{ $sampleInventory[$group] ?? 0 }} units in sample stock)
                                            </option>
                                        @endforeach
                                    </select>
                                    <p
                                        x-show="errors.dispatchBloodGroup"
                                        x-cloak
                                        x-text="errors.dispatchBloodGroup"
                                        class="mt-1 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>

                                <div>
                                    <label for="dispatch-units" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Fulfilled Units to Dispatch <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>
                                    <input
                                        id="dispatch-units"
                                        type="number"
                                        min="1"
                                        :max="remainingUnits"
                                        step="1"
                                        x-model.number="dispatch.units"
                                        class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    />
                                    <p
                                        x-show="errors.dispatchUnits"
                                        x-cloak
                                        x-text="errors.dispatchUnits"
                                        class="mt-1 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>

                                <div>
                                    <label for="dispatch-date" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Dispatch Date <span class="text-crimson-600 dark:text-crimson-400">*</span>
                                    </label>
                                    <input
                                        id="dispatch-date"
                                        type="date"
                                        x-model="dispatch.dispatchDate"
                                        class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    />
                                    <p
                                        x-show="errors.dispatchDate"
                                        x-cloak
                                        x-text="errors.dispatchDate"
                                        class="mt-1 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                                    ></p>
                                </div>

                                <div>
                                    <label for="dispatch-target-status" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Update Fulfillment Status
                                    </label>
                                    <select
                                        id="dispatch-target-status"
                                        x-model="dispatch.targetStatus"
                                        class="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                    >
                                        <option value="auto">Auto-resolve from units (Recommended)</option>
                                        <option value="Partially Fulfilled">Partially Fulfilled</option>
                                        <option value="Fulfilled">Fulfilled</option>
                                    </select>
                                </div>

                                <div class="sm:col-span-2">
                                    <label for="dispatch-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        Dispatch Notes
                                    </label>
                                    <textarea
                                        id="dispatch-notes"
                                        rows="2"
                                        x-model="dispatch.notes"
                                        placeholder="Courier seal number, cold-chain transport box ID, or receiving hospital officer..."
                                        class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                                    ></textarea>
                                </div>
                            </div>

                            {{-- Live Calculated Preview Strip --}}
                            <div class="rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800/50">
                                <template x-if="previewResult.ok">
                                    <div class="flex flex-wrap items-center justify-between gap-2 text-slate-700 dark:text-slate-200">
                                        <span>
                                            Projected total:
                                            <strong x-text="`${previewResult.fulfilledUnits}/${requestedUnits} fulfilled`"></strong>
                                            &middot;
                                            <strong x-text="`${previewResult.remainingUnits} remaining`"></strong>
                                        </span>
                                        <span>
                                            Resulting status:
                                            <strong x-text="previewResult.status"></strong>
                                        </span>
                                    </div>
                                </template>
                                <template x-if="!previewResult.ok">
                                    <p class="font-medium text-crimson-600 dark:text-crimson-400" x-text="previewResult.error"></p>
                                </template>
                            </div>

                            <div class="flex items-center justify-between gap-3 pt-1">
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Preview workflow only — actual Inventory balances remain unchanged.
                                </p>

                                <x-ui.button variant="primary" size="md" type="submit">
                                    <x-icons.icon name="check" class="size-4" />
                                    <span>Record Fulfillment</span>
                                </x-ui.button>
                            </div>
                        </form>
                    </div>
                </x-ui.card>

                {{-- Status & Fulfillment History --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    Status &amp; Fulfillment History
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Chronological audit log of request approvals, dispatches, and rejections.
                                </p>
                            </div>

                            <x-ui.badge variant="neutral">
                                <span x-text="history.length">{{ count($bloodRequest['history']) }}</span> events
                            </x-ui.badge>
                        </div>
                    </x-slot:header>

                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        <template x-for="(entry, idx) in history" :key="idx">
                            <div class="flex flex-col gap-2 px-6 py-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-slate-900 dark:text-white" x-text="entry.event"></span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                            :class="statusBadgeClasses(entry.status)"
                                            x-text="entry.status"
                                        ></span>
                                        <template x-if="entry.units > 0">
                                            <span
                                                class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70"
                                                x-text="`+${entry.units} units`"
                                            ></span>
                                        </template>
                                    </div>

                                    <p class="text-sm text-slate-600 dark:text-slate-300" x-text="entry.notes"></p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Updated by <span class="font-medium text-slate-700 dark:text-slate-200" x-text="entry.actor"></span>
                                    </p>
                                </div>

                                <span class="shrink-0 text-xs font-medium text-slate-500 dark:text-slate-400" x-text="entry.date"></span>
                            </div>
                        </template>

                        {{-- Server-rendered history fallback for non-JS assertions & SEO --}}
                        <noscript>
                            @foreach ($bloodRequest['history'] as $entry)
                                <div class="px-6 py-4">
                                    <p class="font-semibold text-slate-900">{{ $entry['event'] }} ({{ $entry['status'] }})</p>
                                    <p class="text-sm text-slate-600">{{ $entry['notes'] }}</p>
                                </div>
                            @endforeach
                        </noscript>
                    </div>
                </x-ui.card>
            </div>
        </div>

        {{-- Reject Request Modal --}}
        <x-ui.modal name="reject-request-modal" title="Reject Blood Request" max-width="md">
            <form @submit.prevent="submitRejection()" class="space-y-4">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Provide a reason for rejecting <strong>{{ $bloodRequest['id'] }}</strong> ({{ $bloodRequest['hospitalName'] }}). Rejected requests are permanently locked from fulfillment.
                </p>

                <div>
                    <label for="rejection-reason" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                        Rejection Reason <span class="text-crimson-600 dark:text-crimson-400">*</span>
                    </label>
                    <textarea
                        id="rejection-reason"
                        rows="3"
                        x-model="rejectionReason"
                        placeholder="e.g. Duplicate order, incompatible clinical request, or redirected to regional partner bank..."
                        class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500"
                    ></textarea>
                    <p
                        x-show="errors.rejectionReason"
                        x-cloak
                        x-text="errors.rejectionReason"
                        class="mt-1.5 text-xs font-medium text-crimson-600 dark:text-crimson-400"
                    ></p>
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
    </div>
</x-app-layout>

