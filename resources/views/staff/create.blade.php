<x-app-layout :title="__('Add Staff Member')" :page-title="__('Add Staff Member')" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('staff.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Staff Directory') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Add Staff Member') }}</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Add Medical / Operational Staff Member') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Register an employee profile for collection and clinical attribution without creating a login account automatically.') }}
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('staff.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Staff Directory') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('staff._form', [
            'mode' => 'create',
            'professionalRoles' => $professionalRoles,
            'departments' => $departments,
            'statuses' => $statuses,
            'linkableUsers' => $linkableUsers,
        ])
    </div>
</x-app-layout>

