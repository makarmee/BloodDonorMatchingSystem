<x-app-layout :title="$hospital->name . ' — Partner Hospital'" page-title="Hospital Details" active-role="admin">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <a
                    href="{{ route('hospitals.index') }}"
                    class="inline-flex size-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-700 shadow-xs transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    aria-label="Back to hospitals queue"
                >
                    <x-icons.icon name="arrow-left" class="size-4" />
                </a>
                <div>
                    <div class="flex items-center gap-2.5">
                        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            {{ $hospital->name }}
                        </h1>
                        @php
                            $badgeVariant = match ($hospital->verification_status) {
                                'Verified' => 'success',
                                'Suspended' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <x-ui.badge :variant="$badgeVariant" :dot="true">
                            {{ $hospital->verification_status }}
                        </x-ui.badge>
                    </div>
                    <p class="mt-1 font-mono text-xs font-semibold text-slate-500 dark:text-slate-400">
                        {{ $hospital->hospital_code }} &bull; {{ $hospital->city }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @can('verify', $hospital)
                    @if ($hospital->verification_status === 'Pending')
                        <form method="POST" action="{{ route('hospitals.verify', $hospital) }}" onsubmit="return confirm('Verify this partner hospital and grant request privileges?');">
                            @csrf
                            <x-ui.button variant="primary" size="md" type="submit">
                                <x-icons.icon name="shield-check" class="size-4" />
                                <span>Verify Hospital</span>
                            </x-ui.button>
                        </form>
                    @elseif ($hospital->verification_status === 'Suspended')
                        <form method="POST" action="{{ route('hospitals.verify', $hospital) }}" onsubmit="return confirm('Re-verify this suspended hospital and restore request privileges?');">
                            @csrf
                            <x-ui.button variant="primary" size="md" type="submit">
                                <x-icons.icon name="shield-check" class="size-4" />
                                <span>Re-Verify Hospital</span>
                            </x-ui.button>
                        </form>
                    @endif
                @endcan

                @can('suspend', $hospital)
                    @if ($hospital->verification_status === 'Verified')
                        <form method="POST" action="{{ route('hospitals.suspend', $hospital) }}" onsubmit="return confirm('Are you sure you want to suspend this hospital? Its coordinators will lose the ability to place blood requests.');">
                            @csrf
                            <x-ui.button variant="danger" size="md" type="submit">
                                <x-icons.icon name="exclamation-triangle" class="size-4" />
                                <span>Suspend Hospital</span>
                            </x-ui.button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>
    </x-slot:header>

    <div class="space-y-6">
        {{-- Status Notification Banner --}}
        @if ($hospital->verification_status === 'Pending')
            <x-ui.alert variant="warning" title="Hospital Pending Verification" :dismissible="false">
                This medical facility is currently pending administrative review. Clinical coordinators associated with this facility cannot place blood requests until an administrator verifies the account.
            </x-ui.alert>
        @elseif ($hospital->verification_status === 'Verified')
            <x-ui.alert variant="success" title="Verified Partner Facility" :dismissible="false">
                This hospital facility has been verified and authorized for regional blood distribution.
                @if ($hospital->verifiedBy)
                    Verified by <strong>{{ $hospital->verifiedBy->name }}</strong> on {{ $hospital->verified_at?->format('M d, Y \a\t H:i') }}.
                @endif
            </x-ui.alert>
        @elseif ($hospital->verification_status === 'Suspended')
            <x-ui.alert variant="danger" title="Partner Facility Suspended" :dismissible="false">
                This facility has been suspended by an administrator. Clinical coordinators can still authenticate to view past records, but all new blood request submissions are strictly rejected.
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Facility Details --}}
            <div class="space-y-6 lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Facility Information
                        </h2>
                    </x-slot:header>

                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Legal Facility Name</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $hospital->name }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Facility Code</dt>
                            <dd class="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">{{ $hospital->hospital_code }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">City / Region</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $hospital->city }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Facility Active State</dt>
                            <dd class="mt-1">
                                <x-ui.badge :variant="$hospital->is_active ? 'info' : 'neutral'">
                                    {{ $hospital->is_active ? 'Active' : 'Inactive' }}
                                </x-ui.badge>
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Street Address</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100 whitespace-pre-line">{{ $hospital->address }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Registered On</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $hospital->created_at?->format('F d, Y \a\t H:i') ?? '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Last Record Update</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $hospital->updated_at?->format('F d, Y \a\t H:i') ?? '—' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                {{-- Primary Contact Details --}}
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Primary Facility Contact
                        </h2>
                    </x-slot:header>

                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Contact Officer</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $hospital->primary_contact_name }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Primary Phone</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $hospital->primary_phone }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">Official Email</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $hospital->primary_email }}</dd>
                        </div>
                    </dl>
                </x-ui.card>

                {{-- Registered Coordinators Table --}}
                <x-ui.card :padding="false">
                    <x-slot:header>
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Associated Portal Coordinators ({{ $hospital->hospitalUsers->count() }})
                            </h2>
                        </div>
                    </x-slot:header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                            <thead class="bg-slate-50/80 text-xs font-semibold tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400">
                                <tr>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Coordinator</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Department / Title</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Contact Info</th>
                                    <th scope="col" class="px-6 py-3 whitespace-nowrap">Role Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-900">
                                @forelse ($hospital->hospitalUsers as $coordinator)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="font-medium text-slate-900 dark:text-white">
                                                {{ $coordinator->contact_name ?? $coordinator->user?->name ?? 'Coordinator' }}
                                                @if ($coordinator->is_primary_contact)
                                                    <span class="ml-1.5 rounded-full bg-crimson-50 px-2 py-0.5 text-[10px] font-semibold text-crimson-700 dark:bg-crimson-950/70 dark:text-crimson-300">
                                                        Primary
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">
                                                User ID #{{ $coordinator->user_id }}
                                            </div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                            <div>{{ $coordinator->job_title ?? 'Clinical Coordinator' }}</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ $coordinator->department ?? 'General' }}</div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap text-slate-600 dark:text-slate-300">
                                            <div>{{ $coordinator->work_email ?? $coordinator->user?->email }}</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ $coordinator->work_phone ?? '—' }}</div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <x-ui.badge :variant="$coordinator->is_active ? 'info' : 'neutral'">
                                                {{ $coordinator->is_active ? 'Active' : 'Inactive' }}
                                            </x-ui.badge>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-slate-500 dark:text-slate-400">
                                            No coordinator accounts linked to this facility.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>

            {{-- Sidebar / Audit Metadata & Actions --}}
            <div class="space-y-6">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                            Verification Audit
                        </h2>
                    </x-slot:header>

                    <div class="space-y-4 text-sm">
                        <div>
                            <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">Current Status</span>
                            <div class="mt-1">
                                <x-ui.badge :variant="$badgeVariant" :dot="true">
                                    {{ $hospital->verification_status }}
                                </x-ui.badge>
                            </div>
                        </div>

                        <div class="border-t border-slate-200/80 pt-3 dark:border-slate-800">
                            <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">Verified By</span>
                            <span class="mt-1 block font-medium text-slate-900 dark:text-white">
                                {{ $hospital->verifiedBy?->name ?? 'Not yet verified' }}
                            </span>
                            @if ($hospital->verifiedBy)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $hospital->verifiedBy->email }}
                                </span>
                            @endif
                        </div>

                        <div class="border-t border-slate-200/80 pt-3 dark:border-slate-800">
                            <span class="block text-xs font-medium text-slate-500 dark:text-slate-400">Verified At</span>
                            <span class="mt-1 block text-slate-900 dark:text-white">
                                {{ $hospital->verified_at?->format('F d, Y \a\t H:i') ?? '—' }}
                            </span>
                        </div>
                    </div>
                </x-ui.card>

                {{-- Action Panel Card --}}
                @can('verify', $hospital)
                    <x-ui.card>
                        <x-slot:header>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                Administrative Actions
                            </h2>
                        </x-slot:header>

                        <div class="space-y-4">
                            @if ($hospital->verification_status === 'Pending')
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    Verifying this facility authorizes coordinators to place blood requests immediately.
                                </p>
                                <form method="POST" action="{{ route('hospitals.verify', $hospital) }}" onsubmit="return confirm('Verify this hospital?');">
                                    @csrf
                                    <x-ui.button variant="primary" size="md" type="submit" class="w-full">
                                        <x-icons.icon name="shield-check" class="size-4" />
                                        <span>Verify Facility</span>
                                    </x-ui.button>
                                </form>
                            @elseif ($hospital->verification_status === 'Verified')
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    Suspending will deactivate request privileges for this facility while keeping historical request data intact.
                                </p>
                                <form method="POST" action="{{ route('hospitals.suspend', $hospital) }}" onsubmit="return confirm('Are you sure you want to suspend this hospital?');" class="space-y-3">
                                    @csrf
                                    <div>
                                        <label for="suspend-reason" class="block text-xs font-medium text-slate-700 dark:text-slate-300">
                                            Suspension Reason (Optional)
                                        </label>
                                        <input
                                            type="text"
                                            id="suspend-reason"
                                            name="reason"
                                            placeholder="e.g. License renewal pending..."
                                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-crimson-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                                        />
                                    </div>
                                    <x-ui.button variant="danger" size="md" type="submit" class="w-full">
                                        <x-icons.icon name="exclamation-triangle" class="size-4" />
                                        <span>Suspend Facility</span>
                                    </x-ui.button>
                                </form>
                            @elseif ($hospital->verification_status === 'Suspended')
                                <p class="text-xs text-slate-600 dark:text-slate-400">
                                    Re-verifying will restore request privileges and record you as the new verifying administrator.
                                </p>
                                <form method="POST" action="{{ route('hospitals.verify', $hospital) }}" onsubmit="return confirm('Re-verify this hospital?');">
                                    @csrf
                                    <x-ui.button variant="primary" size="md" type="submit" class="w-full">
                                        <x-icons.icon name="shield-check" class="size-4" />
                                        <span>Re-Verify Facility</span>
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    </x-ui.card>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>

