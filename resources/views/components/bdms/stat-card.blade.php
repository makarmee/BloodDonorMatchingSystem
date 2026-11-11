@props([
    'label',
    'value',
    'trend' => null,
    'trendTone' => 'neutral',
    'icon' => 'droplet',
    'accent' => 'crimson',
])

@php
    $accentClasses = match ($accent) {
        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-200/70 dark:bg-emerald-950/60 dark:text-emerald-400 dark:ring-emerald-800/60',
        'amber' => 'bg-amber-50 text-amber-600 ring-amber-200/70 dark:bg-amber-950/60 dark:text-amber-400 dark:ring-amber-800/60',
        'sky' => 'bg-sky-50 text-sky-600 ring-sky-200/70 dark:bg-sky-950/60 dark:text-sky-400 dark:ring-sky-800/60',
        default => 'bg-crimson-50 text-crimson-600 ring-crimson-200/70 dark:bg-crimson-950/60 dark:text-crimson-400 dark:ring-crimson-800/60',
    };

    $trendClasses = match ($trendTone) {
        'up' => 'text-emerald-600 dark:text-emerald-400',
        'down' => 'text-rose-600 dark:text-rose-400',
        default => 'text-slate-500 dark:text-slate-400',
    };
@endphp

<x-ui.card {{ $attributes }}>
    <div class="flex items-start justify-between gap-4">
        <div class="space-y-1 min-w-0">
            <p class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">
                {{ $label }}
            </p>
            <p class="text-2xl font-bold tracking-tight tabular-nums text-slate-900 dark:text-white">
                {{ $value }}
            </p>
        </div>

        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $accentClasses }}">
            <x-icons.icon :name="$icon" class="size-5" />
        </div>
    </div>

    @if ($trend)
        <p class="mt-3 text-xs font-medium {{ $trendClasses }}">
            {{ $trend }}
        </p>
    @endif
</x-ui.card>

