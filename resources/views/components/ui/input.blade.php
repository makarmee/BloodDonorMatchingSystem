@props([
    'label' => null,
    'name' => null,
    'id' => null,
    'type' => 'text',
    'hint' => null,
    'error' => null,
    'required' => false,
    'icon' => null,
])

@php
    $inputId = $id ?? $name ?? 'input-' . uniqid();
    $resolvedError = $error ?? ($name ? $errors->first($name) : null);
    $isPassword = $type === 'password';
@endphp

<div class="space-y-1.5" @if ($isPassword) x-data="{ showPassword: false }" @endif>
    @if ($label)
        <label for="{{ $inputId }}" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ $label }}
            @if ($required)
                <span class="text-crimson-600 dark:text-crimson-400" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        @if ($icon)
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400 dark:text-slate-500">
                <x-icons.icon :name="$icon" class="size-4" />
            </div>
        @endif

        <input
            id="{{ $inputId }}"
            @if ($name) name="{{ $name }}" @endif
            @if ($isPassword)
                :type="showPassword ? 'text' : 'password'"
            @else
                type="{{ $type }}"
            @endif
            @if ($required) required @endif
            {{ $attributes->merge([
                'class' => implode(' ', array_filter([
                    'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs transition focus:outline-none focus:ring-2 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500',
                    $icon ? 'pl-10' : null,
                    $isPassword ? 'pr-10' : null,
                    $resolvedError
                        ? 'border-rose-500 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500'
                        : 'border-slate-300 focus:border-crimson-500 focus:ring-crimson-500/20 dark:border-slate-700 dark:focus:border-crimson-400',
                ])),
            ]) }}
        />

        @if ($isPassword)
            <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-pointer"
                :aria-label="showPassword ? 'Hide password' : 'Show password'"
            >
                <span x-show="!showPassword">
                    <x-icons.icon name="eye" class="size-4" />
                </span>
                <span x-show="showPassword" x-cloak>
                    <x-icons.icon name="eye-slash" class="size-4" />
                </span>
            </button>
        @endif
    </div>

    @if ($resolvedError)
        <p class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ $resolvedError }}</p>
    @elseif ($hint)
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>

