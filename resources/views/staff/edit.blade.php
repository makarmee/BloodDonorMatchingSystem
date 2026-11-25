<x-app-layout :title="__('Edit Staff Member') . ' · ' . $staff->full_name" :page-title="__('Edit Staff Member')" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('staff.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Staff Directory') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <a href="{{ route('staff.show', $staff) }}" class="font-mono transition hover:text-crimson-600 dark:hover:text-crimson-400 dir-ltr">
                        {{ $staff->employee_id }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="text-slate-700 dark:text-slate-200">{{ __('Edit') }}</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Edit Staff Member') }} &middot; {{ $staff->full_name }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Update employment details, professional role, department, or active duty status.') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('staff.show', $staff)">
                    <x-icons.icon name="eye" class="size-4" />
                    <span>{{ __('View Profile') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('staff._form', [
            'mode' => 'edit',
            'staff' => $staff,
            'professionalRoles' => $professionalRoles,
            'departments' => $departments,
            'statuses' => $statuses,
            'linkableUsers' => $linkableUsers,
        ])
    </div>
</x-app-layout>

