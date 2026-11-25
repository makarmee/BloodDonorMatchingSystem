<x-app-layout :title="__('Staff Management')" :page-title="__('Staff Directory')" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('Staff Directory') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Manage medical and operational personnel, department assignments, and active duty status.') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="primary" size="md" :href="route('staff.create')">
                    <x-icons.icon name="plus" class="size-4" />
                    <span>{{ __('Add Staff Member') }}</span>
                </x-ui.button>
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

        {{-- Overview Statistics Cards --}}
        <section aria-label="Staff overview statistics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-bdms.stat-card
                :label="__('Total Staff')"
                :value="$summary['totalStaff']"
                :trend="__('Registered medical & operational staff')"
                trend-tone="neutral"
                icon="users"
                accent="crimson"
            />

            <x-bdms.stat-card
                :label="__('Active Staff')"
                :value="$summary['activeStaff']"
                :trend="__('Available for donation assignment')"
                trend-tone="up"
                icon="check-circle"
                accent="emerald"
            />

            <x-bdms.stat-card
                :label="__('Inactive Staff')"
                :value="$summary['inactiveStaff']"
                :trend="__('Deactivated / historical records')"
                trend-tone="neutral"
                icon="clock"
                accent="amber"
            />

            <x-bdms.stat-card
                :label="__('Departments')"
                :value="$summary['departmentsCount']"
                :trend="__('Active operational units covered')"
                trend-tone="neutral"
                icon="building-hospital"
                accent="sky"
            />
        </section>

        {{-- Staff Registry Table Card --}}
        <x-ui.card :padding="false">
            <x-slot:header>
                <form method="GET" action="{{ route('staff.index') }}" class="space-y-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __('Medical & Operational Personnel') }}
                            </h2>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Showing') }} <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $staffMembers->count() }}</span> {{ __('of') }} {{ $staffMembers->total() }} {{ __('staff records') }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2">
                            <x-ui.button variant="secondary" size="sm" type="submit">
                                <x-icons.icon name="funnel" class="size-3.5" />
                                <span>{{ __('Filter') }}</span>
                            </x-ui.button>

                            @if ($filters['search'] !== '' || $filters['department'] !== 'all' || $filters['professional_role'] !== 'all' || $filters['status'] !== 'all')
                                <x-ui.button variant="ghost" size="sm" :href="route('staff.index')">
                                    <x-icons.icon name="x-mark" class="size-3.5" />
                                    <span>{{ __('Reset') }}</span>
                                </x-ui.button>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label for="staff-search" class="sr-only">{{ __('Search by name or Employee ID') }}</label>
                            <x-ui.input
                                id="staff-search"
                                name="search"
                                type="search"
                                icon="magnifying-glass"
                                :placeholder="__('Search name or Employee ID...')"
                                :value="$filters['search']"
                            />
                        </div>

                        <div>
                            <label for="staff-filter-department" class="sr-only">{{ __('Filter by department') }}</label>
                            <select
                                id="staff-filter-department"
                                name="department"
                                onchange="this.form.submit()"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">{{ __('All Departments') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department }}" @selected($filters['department'] === $department)>{{ __($department) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="staff-filter-role" class="sr-only">{{ __('Filter by professional role') }}</label>
                            <select
                                id="staff-filter-role"
                                name="professional_role"
                                onchange="this.form.submit()"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">{{ __('All Professional Roles') }}</option>
                                @foreach ($professionalRoles as $role)
                                    <option value="{{ $role }}" @selected($filters['professional_role'] === $role)>{{ __($role) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="staff-filter-status" class="sr-only">{{ __('Filter by employment status') }}</label>
                            <select
                                id="staff-filter-status"
                                name="status"
                                onchange="this.form.submit()"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                            >
                                <option value="all">{{ __('All Employment Statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </form>
            </x-slot:header>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-start text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50/80 text-start text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Employee ID') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Full Name') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Professional Role') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Department') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Phone Number') }}</th>
                            <th scope="col" class="px-6 py-3.5 whitespace-nowrap">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3.5 text-end whitespace-nowrap">{{ __('Actions') }}</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                        @forelse ($staffMembers as $member)
                            @php
                                $statusVariant = $member->isActive() ? 'success' : 'neutral';
                                $initials = collect(explode(' ', $member->full_name))
                                    ->filter()
                                    ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
                                    ->take(2)
                                    ->implode('');
                            @endphp

                            <tr class="transition hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                <td class="px-6 py-4 font-mono text-xs font-semibold whitespace-nowrap text-slate-700 dark:text-slate-200 dir-ltr text-start">
                                    <a href="{{ route('staff.show', $member) }}" class="hover:text-crimson-600 dark:hover:text-crimson-400">
                                        {{ $member->employee_id }}
                                    </a>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-crimson-50 text-xs font-bold text-crimson-700 ring-1 ring-crimson-200/70 dark:bg-crimson-500/15 dark:text-crimson-300 dark:ring-crimson-500/30">
                                            {{ $initials ?: 'ST' }}
                                        </span>
                                        <div>
                                            <a
                                                href="{{ route('staff.show', $member) }}"
                                                class="font-semibold text-slate-900 transition hover:text-crimson-600 dark:text-white dark:hover:text-crimson-400"
                                            >
                                                {{ $member->full_name }}
                                            </a>
                                            @if ($member->email)
                                                <p class="text-xs text-slate-500 dark:text-slate-400 dir-ltr text-start">
                                                    {{ $member->email }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-700 dark:text-slate-200">
                                    {{ __($member->professional_role) }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    {{ __($member->department) }}
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                    <span class="inline-flex items-center gap-1.5 dir-ltr text-start">
                                        <x-icons.icon name="phone" class="size-3.5 text-slate-400 dark:text-slate-500" />
                                        <span>{{ $member->phone }}</span>
                                    </span>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap">
                                    <x-ui.badge :variant="$statusVariant" :dot="true">
                                        {{ __($member->status) }}
                                    </x-ui.badge>
                                </td>

                                <td class="px-6 py-4 text-end whitespace-nowrap">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        <x-ui.button variant="ghost" size="sm" :href="route('staff.show', $member)">
                                            <x-icons.icon name="eye" class="size-4" />
                                            <span>{{ __('View') }}</span>
                                        </x-ui.button>

                                        <x-ui.button variant="outline" size="sm" :href="route('staff.edit', $member)">
                                            <x-icons.icon name="pencil" class="size-3.5" />
                                            <span>{{ __('Edit') }}</span>
                                        </x-ui.button>

                                        <form method="POST" action="{{ route('staff.toggle-status', $member) }}" class="inline">
                                            @csrf
                                            <x-ui.button variant="ghost" size="sm" type="submit">
                                                <span>{{ $member->isActive() ? __('Deactivate') : __('Activate') }}</span>
                                            </x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                    <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800">
                                        <x-icons.icon name="users" class="size-6" />
                                    </div>
                                    <p class="mt-3 font-semibold text-slate-900 dark:text-white">{{ __('No staff members found') }}</p>
                                    <p class="mt-1 text-xs">{{ __('Try adjusting your search or filters, or add a new staff member to the directory.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($staffMembers->hasPages())
                <x-slot:footer>
                    {{ $staffMembers->links() }}
                </x-slot:footer>
            @endif
        </x-ui.card>
    </div>
</x-app-layout>

