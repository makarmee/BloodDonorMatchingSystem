<x-app-layout title="Create Blood Request" page-title="New Blood Request" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('requests.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Blood Requests
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">New Request</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Create New Blood Request
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Log a hospital blood unit order with emergency priority, clinical justification, and delivery contact details.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('requests.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Requests</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('requests._form', [
            'mode' => 'create',
            'bloodGroups' => $bloodGroups,
            'priorities' => $priorities,
        ])
    </div>
</x-app-layout>

