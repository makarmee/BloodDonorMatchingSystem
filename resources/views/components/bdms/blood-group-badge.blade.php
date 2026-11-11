@props([
    'group' => 'O+',
    'size' => 'md',
])

@php
    $normalized = strtoupper(trim($group));

    $colorClasses = match (true) {
        str_starts_with($normalized, 'O') => 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/70 dark:text-crimson-300 dark:ring-crimson-800/70',
        str_starts_with($normalized, 'AB') => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:ring-violet-800/70',
        str_starts_with($normalized, 'A') => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
        str_starts_with($normalized, 'B') => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
        default => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-2 py-0.5 text-xs min-w-9',
        'lg' => 'px-3.5 py-1.5 text-sm min-w-12',
        default => 'px-2.5 py-1 text-xs min-w-10',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-lg font-bold tracking-tight tabular-nums ring-1 ring-inset {$colorClasses} {$sizeClasses}"]) }}>
    {{ $normalized }}
</span>

