<x-app-layout :page-title="__('Inventory Reconciliation')" :active-role="$activeRole">
    <x-slot:header>
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('Inventory Audit & Reconciliation') }}
                    </h2>
                    @if ($allReconciled)
                        <x-ui.badge variant="success" :dot="true">{{ __('100% Reconciled') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="danger" :dot="true">{{ __('Discrepancies Detected') }}</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Read-only mathematical verification comparing physical BloodUnits, InventoryStock buckets, and the append-only InventoryTransaction ledger.') }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <x-ui.button variant="outline" size="sm" :href="route('inventory.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Inventory') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        @if ($allReconciled)
            <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                <x-icons.icon name="check-circle" class="size-5 mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div class="text-xs space-y-1">
                    <p class="font-semibold text-sm">{{ __('All Inventory Stock Buckets Reconciled') }}</p>
                    <p class="opacity-90">{{ __('Every active stock bucket balance matches serialized physical units and the immutable ledger history perfectly. No silent changes were performed.') }}</p>
                </div>
            </div>
        @else
            <div class="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-900 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-200">
                <x-icons.icon name="exclamation-triangle" class="size-5 mt-0.5 shrink-0 text-rose-600 dark:text-rose-400" />
                <div class="text-xs space-y-1">
                    <p class="font-semibold text-sm">{{ __('Discrepancies Identified') }}</p>
                    <p class="opacity-90">{{ __('One or more buckets exhibit balance discrepancies against unit records or ledger transaction history. Under BDMS integrity rules, discrepancies are reported for authorized investigation and never silently auto-repaired.') }}</p>
                </div>
            </div>
        @endif

        <div class="grid gap-4">
            @foreach ($reports as $report)
                <x-ui.card :padding="false">
                    <div class="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <x-bdms.blood-group-badge :group="$report['blood_group']" size="md" />
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                    <span class="dir-ltr">{{ $report['blood_group'] }}</span> &middot; {{ $report['component_name'] ?? 'Component ID '.$report['component_id'] }}
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Stock Bucket #:id', ['id' => $report['inventory_stock_id'] ?? $report['stock_id']]) }} &middot; {{ __(':count ledger events', ['count' => $report['transaction_count']]) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($report['is_reconciled'])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70">
                                    <x-icons.icon name="check" class="size-3.5" />
                                    <span>{{ __('Reconciled') }}</span>
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70">
                                    <x-icons.icon name="x-mark" class="size-3.5" />
                                    <span>{{ __(':count Discrepancy', ['count' => count($report['discrepancies'])]) }}</span>
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 text-xs bg-slate-50/50 dark:bg-slate-900/40">
                        <div>
                            <span class="text-slate-500 dark:text-slate-400">{{ __('Stock Available:') }}</span>
                            <strong class="ms-1 text-slate-900 dark:text-white">{{ $report['stock_available_units'] ?? $report['stock_available'] }}</strong>
                        </div>
                        <div>
                            <span class="text-slate-500 dark:text-slate-400">{{ __('Unit Available:') }}</span>
                            <strong class="ms-1 text-slate-900 dark:text-white">{{ $report['unit_available_count'] }}</strong>
                        </div>
                        <div>
                            <span class="text-slate-500 dark:text-slate-400">{{ __('Stock Reserved:') }}</span>
                            <strong class="ms-1 text-slate-900 dark:text-white">{{ $report['stock_reserved_units'] ?? $report['stock_reserved'] }}</strong>
                        </div>
                        <div>
                            <span class="text-slate-500 dark:text-slate-400">{{ __('Unit Reserved:') }}</span>
                            <strong class="ms-1 text-slate-900 dark:text-white">{{ $report['unit_reserved_count'] }}</strong>
                        </div>
                    </div>

                    @if (! empty($report['discrepancies']))
                        <div class="p-4 border-t border-rose-100 bg-rose-50/30 text-xs text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300">
                            <p class="font-semibold mb-1">{{ __('Discrepancy Details:') }}</p>
                            <ul class="list-disc ps-5 space-y-0.5">
                                @foreach ($report['discrepancies'] as $disc)
                                    <li>{{ $disc }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    </div>
</x-app-layout>
