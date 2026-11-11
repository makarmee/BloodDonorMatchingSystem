@props([
    'name',
    'title' => null,
    'maxWidth' => 'lg',
])

@php
    $widthClass = match ($maxWidth) {
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
        default => 'sm:max-w-lg',
    };
@endphp

<div
    x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true"
    x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
    x-on:keydown.escape.window="open = false"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
>
    <div
        x-show="open"
        x-transition.opacity.duration.200ms
        @click="open = false"
        class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs"
    ></div>

    <div
        x-show="open"
        x-transition.scale.95.opacity.duration.200ms
        class="relative w-full {{ $widthClass }} rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900"
    >
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>
                <button
                    type="button"
                    @click="open = false"
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200 cursor-pointer"
                    aria-label="Close modal"
                >
                    <x-icons.icon name="x-mark" class="size-4" />
                </button>
            </div>
        @endif

        <div class="p-5">
            {{ $slot }}
        </div>
    </div>
</div>

