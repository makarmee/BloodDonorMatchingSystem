<x-app-layout :title="__('Staff Profile') . ' · ' . $staff->full_name" :page-title="__('Staff Profile')" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <a href="{{ route('staff.index') }}" class="transition hover:text-crimson-600 dark:hover:text-crimson-400">
                        {{ __('Staff Directory') }}
                    </a>
                    <x-icons.icon name="chevron-right" class="size-3.5 rtl-flip" />
                    <span class="font-mono text-slate-700 dark:text-slate-200 dir-ltr">{{ $staff->employee_id }}</span>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                        {{ $staff->full_name }}
                    </h1>
                    <x-ui.badge :variant="$staff->isActive() ? 'success' : 'neutral'" :dot="true">
                        {{ __($staff->status) }}
                    </x-ui.badge>
                    <x-ui.badge variant="info">
                        {{ __($staff->professional_role) }}
                    </x-ui.badge>
                </div>

                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Employee ID') }}: <span class="font-mono dir-ltr">{{ $staff->employee_id }}</span> &middot; {{ __($staff->department) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" size="md" :href="route('staff.index')">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Staff Directory') }}</span>
                </x-ui.button>

                <x-ui.button variant="outline" size="md" :href="route('staff.edit', $staff)">
                    <x-icons.icon name="pencil" class="size-4" />
                    <span>{{ __('Edit') }}</span>
                </x-ui.button>

                <form method="POST" action="{{ route('staff.toggle-status', $staff) }}" class="inline">
                    @csrf
                    <x-ui.button :variant="$staff->isActive() ? 'secondary' : 'primary'" size="md" type="submit">
                        <span>{{ $staff->isActive() ? __('Deactivate') : __('Activate') }}</span>
                    </x-ui.button>
                </form>

                @if ($staff->donations_count === 0)
                    <form
                        method="POST"
                        action="{{ route('staff.destroy', $staff) }}"
                        onsubmit="return confirm('{{ __('Are you sure you want to remove this staff member from the directory?') }}');"
                        class="inline"
                    >
                        @csrf
                        @method('DELETE')
                        <x-ui.button variant="danger" size="md" type="submit">
                            <span>{{ __('Delete') }}</span>
                        </x-ui.button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success" :title="session('success')" />
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger" :title="session('error')" />
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Employment & Contact Details') }}
                        </h2>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Employee ID') }}</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white dir-ltr text-start">{{ $staff->employee_id }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Professional Role') }}</dt>
                            <dd class="font-medium text-slate-900 dark:text-white">{{ __($staff->professional_role) }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Department') }}</dt>
                            <dd class="text-end font-medium text-slate-900 dark:text-white">{{ __($staff->department) }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Phone Number') }}</dt>
                            <dd class="text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $staff->phone }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Email Address') }}</dt>
                            <dd class="truncate text-slate-700 dark:text-slate-200 dir-ltr text-start">{{ $staff->email ?? __('Not provided') }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Assigned Donations') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white">{{ $staff->donations_count }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Linked Login Account') }}</dt>
                            <dd class="text-end text-slate-700 dark:text-slate-200">
                                @if ($staff->user)
                                    <span class="font-medium">{{ $staff->user->name }}</span>
                                    <span class="block text-xs text-slate-500 dir-ltr">{{ $staff->user->email }}</span>
                                @else
                                    <span class="text-xs text-slate-400 italic">{{ __('None (No Login Account)') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>

                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            {{ __('Notes') }}
                        </h2>
                    </x-slot:header>

                    <p class="text-sm leading-relaxed text-slate-700 dark:text-slate-300">
                        {{ $staff->notes ?: __('No operational notes recorded.') }}
                    </p>
                </x-ui.card>
            </div>

            <div class="space-y-6 lg:col-span-2">
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                    {{ __('Associated Donations History') }}
                                </h2>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ __('Recent blood donations attended or recorded under this staff profile.') }}
                                </p>
                            </div>
                        </div>
                    </x-slot:header>

                    @if ($recentDonations->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-start text-sm dark:divide-slate-800">
                                <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                                    <tr>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Donation ID') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Donor Name') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Donation Date') }}</th>
                                        <th scope="col" class="px-5 py-3 text-start">{{ __('Collection Status') }}</th>
                                        <th scope="col" class="px-5 py-3 text-end">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                    @foreach ($recentDonations as $donation)
                                        <tr class="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                            <td class="px-5 py-3.5 font-mono text-xs font-semibold text-slate-800 dark:text-slate-200 dir-ltr">
                                                {{ $donation->donation_code }}
                                            </td>
                                            <td class="px-5 py-3.5 text-slate-800 dark:text-slate-200">
                                                {{ $donation->donor?->full_name ?? '—' }}
                                            </td>
                                            <td class="px-5 py-3.5 text-xs text-slate-600 dark:text-slate-300">
                                                {{ $donation->donation_date?->format('M d, Y') ?? '—' }}
                                            </td>
                                            <td class="px-5 py-3.5">
                                                <x-ui.badge :variant="$donation->collection_status === 'Completed' ? 'success' : ($donation->collection_status === 'Cancelled' ? 'danger' : 'info')" :dot="true">
                                                    {{ __($donation->collection_status) }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="px-5 py-3.5 text-end">
                                                <x-ui.button variant="ghost" size="sm" :href="route('donations.show', $donation->donation_code)">
                                                    <span>{{ __('View') }}</span>
                                                </x-ui.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="px-6 py-10 text-center">
                            <p class="text-sm text-slate-500 dark:text-slate-400">
                                {{ __('No donations have been assigned to this staff member yet.') }}
                            </p>
                        </div>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>

