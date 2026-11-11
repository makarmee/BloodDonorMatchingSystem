<x-app-layout :title="'Edit Blood Request · ' . $bloodRequest['id']" page-title="Edit Request" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('requests.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Blood Requests
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <a href="{{ route('requests.show', $bloodRequest['id']) }}" class="font-mono transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ $bloodRequest['id'] }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Edit</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Edit Request · {{ $bloodRequest['id'] }}
                    </h1>
                    <x-bdms.blood-group-badge :group="$bloodRequest['bloodGroup']" size="sm" />
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Modify hospital contact details, priority, required units, or notes for {{ $bloodRequest['hospitalName'] }}.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('requests.show', $bloodRequest['id'])">
                    <x-icons.icon name="eye" class="size-4" />
                    <span>View Request</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('requests._form', [
            'mode' => 'edit',
            'bloodRequest' => $bloodRequest,
            'bloodGroups' => $bloodGroups,
            'priorities' => $priorities,
        ])
    </div>
</x-app-layout>

