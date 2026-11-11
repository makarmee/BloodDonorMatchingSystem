@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'options' => [],
    'placeholder' => null,
    'value' => null,
    'error' => null,
    'required' => false,
])

@php
    $selectId = $id ?? $name ?? 'select-' . uniqid();
    $resolvedError = $error ?? ($name ? $errors->first($name) : null);
    $selectedValue = $name ? old($name, $value) : $value;
@endphp

<div class="space-y-1.5">
    @if ($label)
        <label for="{{ $selectId }}" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ $label }}
            @if ($required)
                <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <select
        id="{{ $selectId }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($required) required @endif
        {{ $attributes->merge([
            'class' => implode(' ', [
                'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100',
                $resolvedError
                    ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                    : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400',
            ]),
        ]) }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $selectedValue === (string) $optionValue)>
                {{ $optionLabel }}
            </option>
        @endforeach

        {{ $slot }}
    </select>

    @if ($resolvedError)
        <p class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ $resolvedError }}</p>
    @endif
</div>

