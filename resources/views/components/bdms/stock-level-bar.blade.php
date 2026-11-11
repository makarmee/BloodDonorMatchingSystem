@props([
    'group' => 'O+',
    'units' => 0,
    'capacity' => 100,
    'status' => null,
])

@php
    $percentage = $capacity > 0 ? min(100, (int) round(($units / $capacity) * 100)) : 0;

    $resolvedStatus = $status ?? match (true) {
        $percentage <= 25 => 'critical',
        $percentage <= 50 => 'low',
        default => 'healthy',
    };

    $barClasses = match ($resolvedStatus) {
        'critical' => 'bg-rose-600 dark:bg-rose-500',
        'low' => 'bg-amber-500 dark:bg-amber-400',
        default => 'bg-emerald-600 dark:bg-emerald-500',
    };

    $badgeVariant = match ($resolvedStatus) {
        'critical' => 'danger',
        'low' => 'warning',
        default => 'success',
    };

    $statusLabel = match ($resolvedStatus) {
        'critical' => 'Critical',
        'low' => 'Low Stock',
        'normal' => 'Normal',
        default => 'Adequate',
    };
@endphp

<div {{ $attributes->merge(['class' => 'space-y-2']) }}>
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <x-bdms.blood-group-badge :group="$group" size="sm" />
            <span class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                {{ $units }} <span class="font-normal text-slate-500 dark:text-slate-400">units</span>
            </span>
        </div>

        <x-ui.badge :variant="$badgeVariant" :dot="true">{{ $statusLabel }}</x-ui.badge>
    </div>

    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow="{{ $percentage }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $group }} stock level">
        <div class="h-full rounded-full transition-all duration-300 {{ $barClasses }}" style="width: {{ $percentage }}%"></div>
    </div>
</div>

