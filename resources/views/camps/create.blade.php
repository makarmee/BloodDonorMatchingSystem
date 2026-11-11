<x-app-layout title="Create Donation Camp" page-title="Create Camp" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('camps.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Donation Camps
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Create Camp</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Create Donation Camp
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Schedule a community or institutional blood drive with venue logistics, capacity caps, and registration rules.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('camps.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Camps</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('camps._form', [
            'mode' => 'create',
            'statuses' => $statuses,
        ])
    </div>
</x-app-layout>

