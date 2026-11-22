<x-app-layout :title="__('Edit Donation Camp') . ' · ' . $camp['id']" :page-title="__('Edit Camp')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('camps.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Donation Camps') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <a href="{{ route('camps.show', $camp['id']) }}" class="font-mono transition hover:text-crimson-600 dark:hover:text-crimson-400 dir-ltr">
                        {{ $camp['id'] }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Edit') }}</span>
                </div>

                @php
                    $statusVariant = match ($camp['status']) {
                        'Ongoing' => 'success',
                        'Upcoming' => 'info',
                        'Completed' => 'warning',
                        'Cancelled' => 'danger',
                        default => 'neutral',
                    };
                @endphp

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('Edit Camp') }} &middot; <span class="font-mono dir-ltr">{{ $camp['id'] }}</span>
                    </h1>
                    <x-ui.badge :variant="$statusVariant" :dot="true">
                        {{ __($camp['status']) }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Update schedule, location, capacity limits, or requirements for :name.', ['name' => $camp['name']]) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('camps.show', $camp['id'])">
                    <x-icons.icon name="eye" class="size-4" />
                    <span>{{ __('View Camp') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('camps._form', [
            'mode' => 'edit',
            'camp' => $camp,
            'statuses' => $statuses,
        ])
    </div>
</x-app-layout>
