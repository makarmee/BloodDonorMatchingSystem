<x-app-layout :title="'Edit Donor · ' . $donor['name']" page-title="Edit Donor" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donors.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Donors
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <a href="{{ route('donors.show', $donor['id']) }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ $donor['id'] }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Edit</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Edit Donor · {{ $donor['name'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$donor['bloodGroup']" size="sm" />
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Update contact details, emergency contact, availability, or medical intake notes for {{ $donor['id'] }}.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('donors.show', $donor['id'])">
                    <x-icons.icon name="eye" class="size-4" />
                    <span>View Profile</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('donors._form', [
            'mode' => 'edit',
            'donor' => $donor,
            'bloodGroups' => $bloodGroups,
        ])
    </div>
</x-app-layout>

