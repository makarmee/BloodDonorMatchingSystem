@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'rows' => 3,
    'hint' => null,
    'error' => null,
    'required' => false,
    'value' => null,
])

@php
    $textareaId = $id ?? $name ?? 'textarea-' . uniqid();
    $resolvedError = $error ?? ($name ? $errors->first($name) : null);
    $resolvedValue = $name ? old($name, $value) : $value;
@endphp

<div class="space-y-1.5">
    @if ($label)
        <label for="{{ $textareaId }}" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ $label }}
            @if ($required)
                <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <textarea
        id="{{ $textareaId }}"
        rows="{{ $rows }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($required) required @endif
        {{ $attributes->merge([
            'class' => implode(' ', [
                'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500',
                $resolvedError
                    ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                    : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400',
            ]),
        ]) }}
    >{{ trim((string) $slot) !== '' ? $slot : $resolvedValue }}</textarea>

    @if ($resolvedError)
        <p class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ $resolvedError }}</p>
    @elseif ($hint)
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>

