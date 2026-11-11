@props([
    'variant' => 'neutral',
    'dot' => false,
])

@php
    $variantClasses = match ($variant) {
        'crimson' => 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/70 dark:text-crimson-300 dark:ring-crimson-800/70',
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
        default => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };

    $dotClasses = match ($variant) {
        'crimson' => 'bg-crimson-500',
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-rose-500',
        'info' => 'bg-sky-500',
        default => 'bg-slate-400',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$variantClasses}"]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dotClasses }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>

