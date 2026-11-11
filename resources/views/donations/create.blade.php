<x-app-layout title="Record New Donation" page-title="Add Donation" active-role="staff">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('donations.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        Donations Log
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5" />
                    <span class="text-slate-700 dark:text-slate-200">Add Donation</span>
                </div>

                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Record New Donation
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    Select a sample donor, log collection details, and explicitly record screening and collection statuses.
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('donations.index')">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>Back to Donations</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    <div class="mx-auto max-w-4xl">
        @include('donations._form', [
            'mode' => 'create',
            'donors' => $donors,
            'locations' => $locations,
            'staffMembers' => $staffMembers,
        ])
    </div>
</x-app-layout>

