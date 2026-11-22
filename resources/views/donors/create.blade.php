<x-app-layout :title="__('Register New Donor')" :page-title="__('Register Donor')" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donors.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Donors') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Add Donor') }}</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Register New Donor') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Capture donor demographics, verified blood group, emergency contact, and initial intake notes.') }}
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('donors.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Donors') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('donors._form', [
            'mode' => 'create',
            'bloodGroups' => $bloodGroups,
        ])
    </div>
</x-app-layout>

