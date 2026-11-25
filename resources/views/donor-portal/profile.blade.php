@php
    $donorQuery = request()->filled('donor') ? ['donor' => request()->query('donor')] : [];

    $genderOptions = collect($genders ?? \App\Models\Donor::GENDERS)
        ->mapWithKeys(fn (string $g): array => [$g => __($g)])
        ->all();

    $availabilitySelectOptions = collect($availabilityOptions ?? ['Available', 'Temporarily Unavailable'])
        ->mapWithKeys(fn (string $opt): array => [$opt => __($opt)])
        ->all();

    $eligibilityVariant = match ($profileDetails['eligibilityStatus'] ?? 'Pending Review') {
        'Eligible' => 'success',
        'Not Eligible' => 'danger',
        'Pending Review' => 'warning',
        default => 'neutral',
    };

    $accountVariant = ($profileDetails['accountStatus'] ?? 'Active') === 'Active' ? 'success' : 'neutral';
@endphp

<x-app-layout :title="__('My Profile — Donor Portal')" :page-title="__('My Profile')" active-role="donor">
    <x-slot:header>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __('My Donor Profile') }}
                </h1>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Review your verified donor registration details and update permitted personal or contact information.') }}
                </p>
            </div>

            <div>
                <x-ui.button variant="outline" size="md" :href="route('donor-portal.dashboard', $donorQuery)">
                    <x-icons.icon name="arrow-left" class="size-4 rtl-flip" />
                    <span>{{ __('Back to Overview') }}</span>
                </x-ui.button>
            </div>
        </div>
    </x-slot:header>

    @include('donor-portal._portal-header', ['donorProfile' => $donorProfile])

    <div class="space-y-6">
        @if (session('success'))
            <x-ui.alert variant="success" :title="session('success')" />
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger" :title="__('Unable to Save Profile Changes')">
                <ul class="list-disc list-inside space-y-1 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if (! ($isPersistedDonor ?? true))
            <x-ui.alert variant="info" :title="__('Sample Preview Profile Notice')" :dismissible="false">
                {{ __('You are viewing a sample donor preview fixture. Profile updates are disabled when no live donor profile is linked.') }}
            </x-ui.alert>
        @elseif (! ($profileDetails['profileCompleted'] ?? false))
            <x-ui.alert variant="warning" :title="__('Complete Your Donor Profile')" :dismissible="false">
                {{ __('Please verify and complete your contact phone, date of birth, address, and emergency contact details before scheduling a donation visit.') }}
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Verified Account & Clinical Summary (Read-Only) --}}
            <div class="space-y-6 lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __('Verified Account & Clinical Details') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Managed by authorized blood bank personnel.') }}
                            </p>
                        </div>
                    </x-slot:header>

                    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <div class="flex items-center justify-between gap-3 py-2.5 first:pt-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Donor ID') }}</dt>
                            <dd class="font-mono font-semibold text-slate-900 dark:text-white dir-ltr text-start">{{ $profileDetails['donorCode'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Blood Group') }}</dt>
                            <dd>
                                @if (in_array($profileDetails['bloodGroup'], \App\Models\Donor::BLOOD_GROUPS, true))
                                    <x-bdms.blood-group-badge :group="$profileDetails['bloodGroup']" size="sm" />
                                @else
                                    <span class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ $profileDetails['bloodGroup'] }}</span>
                                @endif
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Email') }}</dt>
                            <dd class="truncate font-medium text-slate-800 dark:text-slate-200 dir-ltr text-start">{{ $profileDetails['email'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Eligibility Status') }}</dt>
                            <dd>
                                <x-ui.badge :variant="$eligibilityVariant" :dot="true">
                                    {{ __($profileDetails['eligibilityStatus']) }}
                                </x-ui.badge>
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Account Status') }}</dt>
                            <dd>
                                <x-ui.badge :variant="$accountVariant" :dot="true">
                                    {{ __($profileDetails['accountStatus']) }}
                                </x-ui.badge>
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Total Donations') }}</dt>
                            <dd class="font-semibold text-slate-900 dark:text-white tabular-nums">{{ $profileDetails['totalDonations'] }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-3 py-2.5 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ __('Registered') }}</dt>
                            <dd class="font-medium text-slate-700 dark:text-slate-300 dir-ltr text-start">{{ $profileDetails['registeredAt'] }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50/70 p-3 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/40 dark:text-slate-300">
                        {{ __('Blood group, eligibility status, email login, and donation totals are protected fields and cannot be altered from the self-service portal.') }}
                    </div>
                </x-ui.card>
            </div>

            {{-- Permitted Personal & Contact Edit Form --}}
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                                {{ __('Personal & Contact Information') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Keep your contact phone, residential address, emergency contact, and availability up to date.') }}
                            </p>
                        </div>
                    </x-slot:header>

                    <form method="POST" action="{{ route('donor-portal.profile.update') }}" class="space-y-5">
                        @csrf
                        @method('PATCH')

                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <x-ui.input
                                    id="donor-profile-full-name"
                                    name="full_name"
                                    :label="__('Full Name')"
                                    :value="old('full_name', $profileDetails['fullName'])"
                                    required
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>

                            <div>
                                <x-ui.input
                                    id="donor-profile-phone"
                                    name="phone"
                                    :label="__('Phone Number')"
                                    :value="old('phone', $profileDetails['phone'])"
                                    placeholder="+93 700 000 000"
                                    required
                                    class="dir-ltr text-start"
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>

                            <div>
                                <x-ui.select
                                    id="donor-profile-gender"
                                    name="gender"
                                    :label="__('Gender')"
                                    :placeholder="__('Select gender')"
                                    :options="$genderOptions"
                                    :value="old('gender', $profileDetails['gender'])"
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>

                            <div>
                                <x-ui.input
                                    id="donor-profile-dob"
                                    name="date_of_birth"
                                    type="date"
                                    :label="__('Date of Birth')"
                                    icon="calendar"
                                    :value="old('date_of_birth', $profileDetails['dateOfBirth'])"
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>

                            <div>
                                <x-ui.select
                                    id="donor-profile-availability"
                                    name="availability_status"
                                    :label="__('Availability Status')"
                                    :options="$availabilitySelectOptions"
                                    :value="old('availability_status', $profileDetails['availabilityStatus'])"
                                    required
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>

                            <div>
                                <x-ui.input
                                    id="donor-profile-emergency"
                                    name="emergency_contact"
                                    :label="__('Emergency Contact')"
                                    :value="old('emergency_contact', $profileDetails['emergencyContact'])"
                                    :placeholder="__('Full name and relationship / phone')"
                                    :disabled="! ($isPersistedDonor ?? true)"
                                />
                            </div>
                        </div>

                        <div>
                            <x-ui.textarea
                                id="donor-profile-address"
                                name="address"
                                :label="__('Address')"
                                rows="3"
                                :value="old('address', $profileDetails['address'])"
                                :placeholder="__('Street address, district, and city')"
                                :disabled="! ($isPersistedDonor ?? true)"
                            />
                        </div>

                        <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-end dark:border-slate-800">
                            <x-ui.button variant="outline" size="md" :href="route('donor-portal.dashboard', $donorQuery)">
                                {{ __('Cancel') }}
                            </x-ui.button>

                            @if ($isPersistedDonor ?? true)
                                <x-ui.button variant="primary" size="md" type="submit">
                                    <x-icons.icon name="check" class="size-4" />
                                    <span>{{ __('Save Profile Changes') }}</span>
                                </x-ui.button>
                            @endif
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    </div>
</x-app-layout>

