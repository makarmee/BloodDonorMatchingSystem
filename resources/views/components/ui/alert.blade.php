@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => true,
])

@php
    $styles = match ($variant) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800/70 dark:bg-emerald-950/60 dark:text-emerald-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800/70 dark:bg-amber-950/60 dark:text-amber-200',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-800/70 dark:bg-rose-950/60 dark:text-rose-200',
        default => 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-800/70 dark:bg-sky-950/60 dark:text-sky-200',
    };

    $iconName = match ($variant) {
        'success' => 'check-circle',
        'warning', 'danger' => 'exclamation-triangle',
        default => 'information-circle',
    };
@endphp

<div
    x-data="{ open: true }"
    x-show="open"
    x-transition.opacity.duration.200ms
    role="alert"
    {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-xl border px-4 py-3 text-sm {$styles}"]) }}
>
    <x-icons.icon :name="$iconName" class="size-5 mt-0.5 shrink-0" />

    <div class="flex-1 min-w-0">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5 opacity-90' => $title])>
            {{ $slot }}
        </div>
    </div>

    @if ($dismissible)
        <button
            type="button"
            @click="open = false"
            class="rounded-md p-1 opacity-70 hover:opacity-100 transition cursor-pointer"
            aria-label="Dismiss alert"
        >
            <x-icons.icon name="x-mark" class="size-4" />
        </button>
    @endif
</div>

