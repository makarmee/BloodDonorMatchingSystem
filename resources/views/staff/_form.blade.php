@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $staffRecord = $staff ?? null;

    $roleOptions = collect($professionalRoles)
        ->mapWithKeys(fn (string $role): array => [$role => __($role)])
        ->all();

    $departmentOptions = collect($departments)
        ->mapWithKeys(fn (string $dept): array => [$dept => __($dept)])
        ->all();

    $statusOptions = collect($statuses)
        ->mapWithKeys(fn (string $status): array => [$status => __($status)])
        ->all();

    $userOptions = collect($linkableUsers ?? [])
        ->mapWithKeys(fn ($user): array => [$user->id => "{$user->name} ({$user->email})"])
        ->all();

    $cancelUrl = $isEdit && $staffRecord
        ? route('staff.show', $staffRecord)
        : route('staff.index');
@endphp

<div class="space-y-6">
    @if ($errors->any())
        <x-ui.alert variant="danger" :title="__('Validation errors occurred')" class="mb-4">
            <ul class="list-disc ps-5 text-xs space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form
        method="POST"
        action="{{ $isEdit && $staffRecord ? route('staff.update', $staffRecord) : route('staff.store') }}"
        class="space-y-6"
    >
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ __('Staff Identification & Position') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ __('Core employee identity, clinical role, department placement, and employment status.') }}
                    </p>
                </div>
            </x-slot:header>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <x-ui.input
                        id="staff-full-name"
                        name="full_name"
                        :label="__('Full Name')"
                        placeholder="e.g. Nadia Rahimi"
                        icon="user"
                        :value="old('full_name', $staffRecord?->full_name ?? '')"
                        required
                    />
                </div>

                <div>
                    <x-ui.input
                        id="staff-employee-id"
                        name="employee_id"
                        :label="__('Employee ID')"
                        placeholder="e.g. EMP-1001"
                        :hint="__('Must be unique across all staff members.')"
                        :value="old('employee_id', $staffRecord?->employee_id ?? '')"
                        class="font-mono dir-ltr"
                        required
                    />
                </div>

                <div>
                    <x-ui.select
                        id="staff-professional-role"
                        name="professional_role"
                        :label="__('Professional Role')"
                        :placeholder="__('Select professional role')"
                        :options="$roleOptions"
                        :value="old('professional_role', $staffRecord?->professional_role ?? '')"
                        required
                    />
                </div>

                <div>
                    <x-ui.select
                        id="staff-department"
                        name="department"
                        :label="__('Department')"
                        :placeholder="__('Select department')"
                        :options="$departmentOptions"
                        :value="old('department', $staffRecord?->department ?? '')"
                        required
                    />
                </div>

                <div>
                    <x-ui.input
                        id="staff-phone"
                        name="phone"
                        type="tel"
                        :label="__('Phone Number')"
                        placeholder="+1-555-010-2400"
                        icon="phone"
                        :value="old('phone', $staffRecord?->phone ?? '')"
                        required
                    />
                </div>

                <div>
                    <x-ui.input
                        id="staff-email"
                        name="email"
                        type="email"
                        :label="__('Email Address')"
                        placeholder="staff@bdms.local"
                        icon="envelope"
                        :hint="__('Optional contact email; does not create a login account.')"
                        :value="old('email', $staffRecord?->email ?? '')"
                    />
                </div>

                <div>
                    <x-ui.select
                        id="staff-status"
                        name="status"
                        :label="__('Employment Status')"
                        :options="$statusOptions"
                        :value="old('status', $staffRecord?->status ?? 'Active')"
                        required
                    />
                </div>

                <div>
                    <x-ui.select
                        id="staff-user-id"
                        name="user_id"
                        :label="__('Linked System User Account')"
                        :placeholder="__('None (No Login Account)')"
                        :options="$userOptions"
                        :value="(string) old('user_id', $staffRecord?->user_id ?? '')"
                        :hint="__('Optional: link this staff member to an existing active BDMS user account.')"
                    />
                </div>

                <div class="md:col-span-2">
                    <x-ui.textarea
                        id="staff-notes"
                        name="notes"
                        :label="__('Notes')"
                        rows="3"
                        :placeholder="__('Optional certifications, shift notes, or operational remarks...')"
                        :value="old('notes', $staffRecord?->notes ?? '')"
                    />
                </div>
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <x-ui.button variant="outline" size="md" :href="$cancelUrl">
                        <span>{{ __('Cancel') }}</span>
                    </x-ui.button>

                    <x-ui.button variant="primary" size="md" type="submit">
                        <x-icons.icon name="check" class="size-4" />
                        <span>{{ $isEdit ? __('Save Changes') : __('Create Staff Member') }}</span>
                    </x-ui.button>
                </div>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>

