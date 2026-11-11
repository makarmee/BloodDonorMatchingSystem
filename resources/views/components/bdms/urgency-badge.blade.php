@props([
    'level' => 'routine',
])

@php
    $normalized = strtolower(trim($level));

    $variant = match ($normalized) {
        'critical', 'emergency' => 'danger',
        'urgent', 'high' => 'warning',
        'fulfilled', 'completed', 'approved' => 'success',
        'pending' => 'info',
        default => 'neutral',
    };
@endphp

<x-ui.badge :variant="$variant" :dot="true" {{ $attributes }}>
    {{ ucfirst($normalized) }}
</x-ui.badge>

