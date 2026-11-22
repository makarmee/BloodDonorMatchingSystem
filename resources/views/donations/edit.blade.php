<x-app-layout :title="__('Edit Donation') . ' · ' . $donation['id']" :page-title="__('Edit Donation')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donations.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Donations Log') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <a href="{{ route('donations.show', $donation['id']) }}" class="font-mono transition hover:text-crimson-600 dark:hover:text-crimson-400 dir-ltr">
                        {{ $donation['id'] }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Edit') }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ __('Edit Donation') }} · <span class="dir-ltr">{{ $donation['id'] }}</span>
                    </h1>
                    <x-bdms.blood-group-badge :group="$donation['bloodGroup']" size="sm" />
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Update collection parameters, attending staff, screening findings, or collection status for :donor.', ['donor' => $donation['donorName']]) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donations.show', $donation['id'])">
                    <x-icons.icon name="eye" class="size-4" />
                    <span>{{ __('View Details') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('donations._form', [
            'mode' => 'edit',
            'donation' => $donation,
            'donors' => $donors,
            'locations' => $locations,
            'staffMembers' => $staffMembers,
        ])
    </div>
</x-app-layout>

