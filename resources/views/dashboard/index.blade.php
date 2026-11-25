<x-app-layout :page-title="__($portalData['pageTitle'])" :active-role="$activeRole">
    <x-slot:header>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ __($portalData['pageTitle']) }}
                </h2>
            </div>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                {{ __($portalData['subtitle']) }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($activeRole === 'hospital')
                @if (isset($hospital) && $hospital && $hospital->verification_status === 'Verified')
                    <x-ui.button variant="primary" size="sm" :href="route('requests.create')">
                        <x-icons.icon name="plus" class="size-4" />
                        <span>{{ __('New Blood Request') }}</span>
                    </x-ui.button>
                @else
                    <x-ui.button variant="outline" size="sm" disabled :title="__('Requires verified hospital status')">
                        <x-icons.icon name="lock-closed" class="size-4" />
                        <span>{{ __('Verification Pending') }}</span>
                    </x-ui.button>
                @endif
            @elseif ($activeRole === 'donor')
                <x-ui.button variant="primary" size="sm" x-data @click="$dispatch('open-modal', 'quick-action-preview')">
                    <x-icons.icon name="calendar" class="size-4" />
                    {{ __('Book Appointment') }}
                </x-ui.button>
            @elseif ($activeRole === 'lab_officer')
                <x-ui.button variant="primary" size="sm" :href="route('screening.index')">
                    <x-icons.icon name="shield-check" class="size-4" />
                    {{ __('Open Screening Queue') }}
                </x-ui.button>
            @elseif (in_array($activeRole, ['admin', 'staff'], true))
                <x-ui.button variant="outline" size="sm" :href="route('home')">
                    {{ __('Public Stock View') }}
                </x-ui.button>
                <x-ui.button variant="primary" size="sm" x-data @click="$dispatch('open-modal', 'record-donation-modal')">
                    <x-icons.icon name="plus" class="size-4" />
                    {{ __('Record Donation') }}
                </x-ui.button>
            @endif
        </div>
    </x-slot:header>

    @if ($activeRole === null)
        <x-ui.card>
            <div class="space-y-2 py-2">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                    {{ __('No Portal Role Assigned') }}
                </h3>
                <p class="text-sm text-slate-600 dark:text-slate-400">
                    {{ __('Your account is authenticated, but you do not currently hold an active BDMS portal role. Please contact an administrator to assign appropriate permissions.') }}
                </p>
            </div>
        </x-ui.card>
    @else
        @if ($activeRole === 'hospital' && isset($hospital) && $hospital)
            @if ($hospital->verification_status === 'Pending')
                <x-ui.alert variant="warning" :title="__('Facility Verification Pending')" :dismissible="false" class="mb-6">
                    {!! __('Your medical facility (:name, Code: :code) is currently pending administrative verification. Blood request submissions are disabled until an administrator approves your facility credentials.', ['name' => '<strong>'.$hospital->name.'</strong>', 'code' => '<code class="dir-ltr">'.$hospital->hospital_code.'</code>']) !!}
                </x-ui.alert>
            @elseif ($hospital->verification_status === 'Verified')
                <x-ui.alert variant="success" :title="__('Verified Hospital Partner')" :dismissible="false" class="mb-6">
                    {!! __('Your medical facility (:name) is verified and active. You are authorized to create blood requests.', ['name' => '<strong>'.$hospital->name.'</strong>']) !!}
                </x-ui.alert>
            @elseif ($hospital->verification_status === 'Suspended')
                <x-ui.alert variant="danger" :title="__('Facility Access Suspended')" :dismissible="false" class="mb-6">
                    {{ __('Your medical facility\'s access is currently suspended. You cannot place new blood requests. Please contact the BDMS administration for assistance.') }}
                </x-ui.alert>
            @endif
        @endif

        {{-- KPI Stat Cards --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($portalData['kpis'] as $kpi)
                <x-bdms.stat-card
                    :label="$kpi['label']"
                    :value="$kpi['value']"
                    :trend="$kpi['trend']"
                    :trend-tone="$kpi['trendTone']"
                    :icon="$kpi['icon']"
                    :accent="$kpi['accent']"
                />
            @endforeach
        </div>

        {{-- Main Two-Column Grid --}}
        <div class="mt-6 grid gap-6 lg:grid-cols-12">
        {{-- Recent Activity / Requests Table --}}
        <div class="lg:col-span-8">
            <x-ui.card :padding="false">
                <x-slot:header>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                                {{ $activeRole === 'donor' ? __('Recent Donations & Visits') : __('Recent Blood Requests') }}
                            </h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ __('Operational activity log and recent submissions') }}
                            </p>
                        </div>
                        <x-ui.badge variant="neutral">{{ __(':count items', ['count' => \App\Support\NumberLocalizer::toPersianDigits(count($portalData['recentActivity']))]) }}</x-ui.badge>
                    </div>
                </x-slot:header>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
                        <thead class="bg-slate-50/70 text-start text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-950/60 dark:text-slate-400">
                            <tr>
                                <th scope="col" class="px-5 py-3">{{ __('Ref') }}</th>
                                <th scope="col" class="px-5 py-3">{{ $activeRole === 'donor' ? __('Center / Event') : __('Hospital / Ward') }}</th>
                                <th scope="col" class="px-5 py-3">{{ __('Group') }}</th>
                                <th scope="col" class="px-5 py-3">{{ __('Quantity') }}</th>
                                <th scope="col" class="px-5 py-3">{{ __('Status') }}</th>
                                <th scope="col" class="px-5 py-3 text-end">{{ __('Time') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                            @forelse ($portalData['recentActivity'] as $row)
                                <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition">
                                    <td class="whitespace-nowrap px-5 py-3.5 font-mono text-xs font-semibold text-slate-700 dark:text-slate-300 dir-ltr text-start">
                                        {{ $row['reference'] }}
                                    </td>
                                    <td class="px-5 py-3.5 font-medium text-slate-900 dark:text-white">
                                        {{ __($row['subject']) }}
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <x-bdms.blood-group-badge :group="$row['group']" size="sm" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-slate-600 dark:text-slate-300">
                                        @num($row['quantity'])
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5">
                                        <x-bdms.urgency-badge :level="$row['status']" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3.5 text-end text-xs text-slate-500 dark:text-slate-400">
                                        {{ __($row['time']) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                                        {{ __('No recent operational activity recorded.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </div>

        {{-- Regional Blood Stock Snapshot --}}
        <div class="lg:col-span-4 space-y-6">
            <x-ui.card>
                <x-slot:header>
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                            {{ __('Critical Stock Watch') }}
                        </h3>
                        <x-icons.icon name="droplet" class="size-4 text-crimson-600 dark:text-crimson-400" />
                    </div>
                </x-slot:header>

                <div class="space-y-4">
                    @forelse ($portalData['inventorySnapshot'] as $stock)
                        <x-bdms.stock-level-bar
                            :group="$stock['group']"
                            :units="$stock['units']"
                            :capacity="$stock['capacity']"
                        />
                    @empty
                        <p class="py-4 text-center text-xs text-slate-500 dark:text-slate-400">
                            {{ __('No inventory stock levels recorded.') }}
                        </p>
                    @endforelse
                </div>

                <x-slot:footer>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {!! __('Role-scoped access for :user.', ['user' => '<span class="font-semibold text-slate-700 dark:text-slate-200">'.(auth()->user()?->name ?? __('Authenticated User')).'</span>']) !!}
                    </p>
                </x-slot:footer>
            </x-ui.card>
        </div>
    </div>
    @endif

    @if (in_array($activeRole, ['admin', 'staff'], true))
        @php
            $modalDonors = $donationFormData['donors'] ?? [];
            $modalCenters = $donationFormData['centers'] ?? [];
            $modalCamps = $donationFormData['camps'] ?? [];
            $modalComponents = $donationFormData['components'] ?? [];
            $modalStaffMembers = $donationFormData['staffMembers'] ?? collect();

            if ($modalStaffMembers instanceof \Illuminate\Support\Collection ? $modalStaffMembers->isEmpty() : empty($modalStaffMembers)) {
                $modalStaffMembers = \App\Models\Staff::query()->active()->orderBy('full_name')->get(['id', 'employee_id', 'full_name', 'professional_role']);
            }

            if (empty($modalDonors)) {
                $modalDonors = \App\Models\Donor::query()->active()->with('currentEligibilityRecord')->orderBy('full_name')->limit(50)->get()->map(fn ($d) => [
                    'id' => $d->donor_code,
                    'name' => $d->full_name,
                    'bloodGroup' => $d->blood_group,
                    'isEligible' => $d->account_status === 'Active' && $d->profile_completed && ($d->currentEligibilityRecord?->eligibility_status === 'Eligible' || $d->eligibility_status === 'Eligible'),
                    'eligibilityStatus' => $d->currentEligibilityRecord?->eligibility_status ?? $d->eligibility_status ?? 'Unknown',
                    'email' => $d->email,
                    'phone' => $d->phone ?? '',
                ])->all();
            }
            if (empty($modalComponents)) {
                $modalComponents = \App\Models\BloodComponent::query()->where('is_active', true)->orderBy('name')->get();
            }
            if (empty($modalCenters)) {
                $modalCenters = \App\Models\DonationCenter::query()->where('is_active', true)->orderBy('name')->get();
            }
            if (empty($modalCamps)) {
                $modalCamps = \App\Models\DonationCamp::query()->whereIn('status', ['Upcoming', 'Ongoing'])->orderBy('name')->get();
            }

            $firstCenterId = !empty($modalCenters) ? (is_array($modalCenters) ? ($modalCenters[0]['id'] ?? $modalCenters[0]->id ?? '') : ($modalCenters->first()?->id ?? '')) : '';
            $firstCampId = !empty($modalCamps) ? (is_array($modalCamps) ? ($modalCamps[0]['id'] ?? $modalCamps[0]->id ?? '') : ($modalCamps->first()?->id ?? '')) : '';
            $firstComponentId = !empty($modalComponents) ? (is_array($modalComponents) ? ($modalComponents[0]['id'] ?? $modalComponents[0]->id ?? '') : ($modalComponents->first()?->id ?? '')) : '';
            $firstComponentVolume = !empty($modalComponents) ? (is_array($modalComponents) ? ($modalComponents[0]['standard_volume_ml'] ?? $modalComponents[0]->standard_volume_ml ?? 450) : ($modalComponents->first()?->standard_volume_ml ?? 450)) : 450;
        @endphp

        <div
            x-data="{
                donors: @js($modalDonors),
                components: @js($modalComponents),
                centers: @js($modalCenters),
                camps: @js($modalCamps),
                facilityType: 'center',
                form: {
                    donorId: '',
                    centerId: '{{ $firstCenterId }}',
                    campId: '',
                    collectionLocation: '',
                    donationDate: '{{ now()->toDateString() }}',
                    donationTime: '{{ now()->format('H:i') }}',
                    componentId: '{{ $firstComponentId }}',
                    quantityMl: {{ (int) $firstComponentVolume }},
                    collectionStatus: 'Completed',
                    storageLocation: 'Main Cold Room Rack 1',
                    staffId: '',
                    staffMember: '{{ addslashes(auth()->user()?->name ?? 'Blood Bank Staff') }}',
                    collectionNotes: ''
                },
                get selectedDonor() {
                    return this.donors.find(d => String(d.id) === String(this.form.donorId)) || null;
                },
                get isDonorEligible() {
                    return this.selectedDonor ? Boolean(this.selectedDonor.isEligible) : false;
                },
                get canSubmit() {
                    return this.form.donorId !== '' && this.isDonorEligible;
                },
                setFacilityType(type) {
                    this.facilityType = type;
                    if (type === 'center') {
                        this.form.campId = '';
                        if (!this.form.centerId && this.centers.length > 0) {
                            const first = this.centers[0];
                            this.form.centerId = first.id || '';
                        }
                    } else {
                        this.form.centerId = '';
                        if (!this.form.campId && this.camps.length > 0) {
                            const first = this.camps[0];
                            this.form.campId = first.id || '';
                        }
                    }
                },
                onComponentChange() {
                    const comp = this.components.find(c => String(c.id) === String(this.form.componentId));
                    if (comp && comp.standard_volume_ml) {
                        this.form.quantityMl = comp.standard_volume_ml;
                    }
                },
                handleSubmit(e) {
                    if (!this.canSubmit) {
                        e.preventDefault();
                    }
                }
            }"
            x-on:open-modal.window="if ($event.detail === 'quick-action-preview') $dispatch('open-modal', 'record-donation-modal')"
        >
            <x-ui.modal name="record-donation-modal" :title="__('Register Blood Donation')" maxWidth="2xl">
                <form
                    method="POST"
                    action="{{ route('donations.store') }}"
                    @submit="handleSubmit($event)"
                    class="space-y-4"
                >
                    @csrf
                    <input type="hidden" name="redirect_to" value="dashboard" />

                    {{-- Donor Selection & Live Eligibility Gate --}}
                    <div>
                        <label for="dashboard-donation-donor" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Select Registered Donor') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                        </label>

                        <select
                            id="dashboard-donation-donor"
                            name="donor_id"
                            x-model="form.donorId"
                            required
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs transition focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        >
                            <option value="">{{ __('Choose a registered donor...') }}</option>
                            @foreach ($modalDonors as $d)
                                <option value="{{ $d['id'] }}">
                                    {{ $d['id'] }} — {{ $d['name'] }} ({{ $d['bloodGroup'] }}) [{{ $d['isEligible'] ? __('Eligible') : __('Ineligible') }}]
                                </option>
                            @endforeach
                        </select>

                        {{-- Selected Donor Details & Eligibility Alert --}}
                        <div x-show="selectedDonor !== null" x-cloak class="mt-2.5 rounded-xl border p-3 transition"
                            :class="selectedDonor?.isEligible
                                ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                                : 'border-crimson-200 bg-crimson-50/70 dark:border-crimson-500/30 dark:bg-crimson-500/10'"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="inline-flex items-center justify-center rounded-lg px-2 py-0.5 text-xs font-bold ring-1 ring-inset"
                                        :class="selectedDonor?.isEligible
                                            ? 'bg-emerald-100 text-emerald-800 ring-emerald-300 dark:bg-emerald-950 dark:text-emerald-300'
                                            : 'bg-crimson-100 text-crimson-800 ring-crimson-300 dark:bg-crimson-950 dark:text-crimson-300'"
                                        x-text="selectedDonor?.bloodGroup"
                                    ></span>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white" x-text="selectedDonor?.name"></p>
                                        <p class="font-mono text-xs text-slate-500 dark:text-slate-400 dir-ltr text-start" x-text="selectedDonor?.id"></p>
                                    </div>
                                </div>

                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="selectedDonor?.isEligible
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                        : 'bg-crimson-100 text-crimson-700 dark:bg-crimson-900/40 dark:text-crimson-300'"
                                >
                                    <span x-text="selectedDonor?.isEligible ? '{{ __('Cleared for Donation') }}' : '{{ __('Ineligible for Donation') }}'"></span>
                                </span>
                            </div>

                            <div x-show="selectedDonor && !selectedDonor.isEligible" class="mt-2 border-t border-crimson-200 pt-2 text-xs text-crimson-700 dark:border-crimson-500/30 dark:text-crimson-300">
                                <p class="font-medium">
                                    {{ __('This donor is currently ineligible and cannot donate blood. Ineligible donors cannot be processed.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Facility & Location Selection --}}
                    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50/60 p-3.5 dark:border-slate-800 dark:bg-slate-800/40">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                {{ __('Collection Facility / Setting') }}
                            </span>
                            <div class="inline-flex rounded-lg bg-slate-200/70 p-0.5 dark:bg-slate-700">
                                <button
                                    type="button"
                                    @click="setFacilityType('center')"
                                    :class="facilityType === 'center' ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-300'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium transition cursor-pointer"
                                >
                                    {{ __('Fixed Donation Center') }}
                                </button>
                                <button
                                    type="button"
                                    @click="setFacilityType('camp')"
                                    :class="facilityType === 'camp' ? 'bg-white text-slate-900 shadow-2xs dark:bg-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-300'"
                                    class="rounded-md px-2.5 py-1 text-xs font-medium transition cursor-pointer"
                                >
                                    {{ __('Mobile Donation Camp') }}
                                </button>
                            </div>
                        </div>

                        <div x-show="facilityType === 'center'">
                            <label for="dashboard-donation-center" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                {{ __('Select Donation Center') }}
                            </label>
                            <select
                                id="dashboard-donation-center"
                                name="center_id"
                                x-model="form.centerId"
                                :disabled="facilityType !== 'center'"
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                @foreach ($modalCenters as $c)
                                    @php
                                        $cId = is_array($c) ? $c['id'] : $c->id;
                                        $cName = is_array($c) ? $c['name'] : $c->name;
                                        $cCity = is_array($c) ? ($c['city'] ?? '') : ($c->city ?? '');
                                    @endphp
                                    <option value="{{ $cId }}">{{ __($cName) }} ({{ __($cCity ?: 'Fixed Center') }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div x-show="facilityType === 'camp'" x-cloak>
                            <label for="dashboard-donation-camp" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                {{ __('Select Donation Camp') }}
                            </label>
                            <select
                                id="dashboard-donation-camp"
                                name="camp_id"
                                x-model="form.campId"
                                :disabled="facilityType !== 'camp'"
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="">{{ __('Choose donation camp...') }}</option>
                                @foreach ($modalCamps as $camp)
                                    @php
                                        $campIdVal = is_array($camp) ? $camp['id'] : $camp->id;
                                        $campName = is_array($camp) ? $camp['name'] : $camp->name;
                                        $campVenue = is_array($camp) ? ($camp['venue_name'] ?? $camp['city'] ?? '') : ($camp->venue_name ?? $camp->city ?? '');
                                    @endphp
                                    <option value="{{ $campIdVal }}">{{ __($campName) }} — {{ __($campVenue) }}</option>
                                @endforeach
                            </select>
                            <p x-show="camps.length === 0" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ __('No active camps currently ongoing.') }}
                            </p>
                        </div>

                        <div>
                            <label for="dashboard-donation-specific-location" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                {{ __('Specific Facility / Station Reference') }}
                            </label>
                            <input
                                type="text"
                                id="dashboard-donation-specific-location"
                                name="collection_location"
                                x-model="form.collectionLocation"
                                placeholder="e.g. Station 2, Phlebotomy Bay A"
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>
                    </div>

                    {{-- Two-column Grid: Date/Time, Component, Volume, Status --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="dashboard-donation-date" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Donation Date') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            <input
                                type="date"
                                id="dashboard-donation-date"
                                name="donation_date"
                                x-model="form.donationDate"
                                :max="form.collectionStatus === 'Completed' ? '{{ now()->toDateString() }}' : null"
                                required
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>

                        <div>
                            <label for="dashboard-donation-time" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Donation Time') }}
                            </label>
                            <input
                                type="time"
                                id="dashboard-donation-time"
                                name="donation_time"
                                x-model="form.donationTime"
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>

                        <div>
                            <label for="dashboard-donation-component" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Blood Component') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            <select
                                id="dashboard-donation-component"
                                name="blood_component_id"
                                x-model="form.componentId"
                                @change="onComponentChange()"
                                required
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                @foreach ($modalComponents as $comp)
                                    @php
                                        $compId = is_array($comp) ? $comp['id'] : $comp->id;
                                        $compName = is_array($comp) ? $comp['name'] : $comp->name;
                                        $compCode = is_array($comp) ? $comp['code'] : $comp->code;
                                    @endphp
                                    <option value="{{ $compId }}">{{ __($compName) }} ({{ $compCode }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="dashboard-donation-quantity" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Quantity (ml)') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            <input
                                type="number"
                                id="dashboard-donation-quantity"
                                name="quantity_ml"
                                x-model="form.quantityMl"
                                min="100"
                                max="1000"
                                step="10"
                                required
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            />
                        </div>

                        <div>
                            <label for="dashboard-donation-status" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Collection Status') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            <select
                                id="dashboard-donation-status"
                                name="collection_status"
                                x-model="form.collectionStatus"
                                required
                                class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                            >
                                <option value="Completed">{{ __('Completed (Blood Drawn & Quarantined)') }}</option>
                                <option value="Scheduled">{{ __('Scheduled (Session Planned)') }}</option>
                            </select>
                        </div>

                        <div>
                            <label for="dashboard-donation-staff" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                                {{ __('Staff Member') }} <span class="text-crimson-600 dark:text-crimson-400">*</span>
                            </label>
                            @if (collect($modalStaffMembers)->isNotEmpty())
                                <select
                                    id="dashboard-donation-staff"
                                    name="staff_id"
                                    x-model="form.staffId"
                                    required
                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                >
                                    <option value="">{{ __('Select active staff member') }}</option>
                                    @foreach ($modalStaffMembers as $staffMember)
                                        <option value="{{ $staffMember->id }}">
                                            {{ $staffMember->full_name }} ({{ $staffMember->employee_id }})
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    type="text"
                                    id="dashboard-donation-staff"
                                    name="staff_member"
                                    x-model="form.staffMember"
                                    required
                                    class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                            @endif
                        </div>
                    </div>

                    {{-- Quarantine Storage (Shown when collection status is Completed) --}}
                    <div x-show="form.collectionStatus === 'Completed'">
                        <label for="dashboard-donation-storage" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Storage Location') }}
                        </label>
                        <input
                            type="text"
                            id="dashboard-donation-storage"
                            name="storage_location"
                            x-model="form.storageLocation"
                            placeholder="e.g. Main Cold Room Rack 1, Quarantine Shelf B"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('Initial quarantine storage shelf before laboratory release.') }}
                        </p>
                    </div>

                    {{-- Collection Notes --}}
                    <div>
                        <label for="dashboard-donation-notes" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            {{ __('Collection Notes') }}
                        </label>
                        <textarea
                            id="dashboard-donation-notes"
                            name="collection_notes"
                            x-model="form.collectionNotes"
                            rows="2"
                            placeholder="{{ __('Venipuncture observations, donor tolerance, tube barcode references...') }}"
                            class="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-2xs focus:border-crimson-500 focus:ring-2 focus:ring-crimson-500/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                        ></textarea>
                    </div>

                    {{-- Modal Actions --}}
                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-200 dark:border-slate-800">
                        <button
                            type="button"
                            @click="$dispatch('close-modal', 'record-donation-modal')"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 cursor-pointer"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="!canSubmit"
                            :class="canSubmit ? 'bg-crimson-600 hover:bg-crimson-700 cursor-pointer text-white shadow-xs' : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed'"
                            class="inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold transition"
                        >
                            <x-icons.icon name="check" class="size-4" />
                            <span>{{ __('Record Donation') }}</span>
                        </button>
                    </div>
                </form>
            </x-ui.modal>
        </div>
    @else
        <x-ui.modal name="quick-action-preview" :title="__('Module Action Preview')" maxWidth="md">
            <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                {{ __('This modal demonstrates the reusable :component component. Dedicated CRUD forms for Inventory, Donors, Donations, and Blood Requests can be built in the next phase.', ['component' => 'x-ui.modal']) }}
            </p>
            <div class="mt-5 flex justify-end">
                <x-ui.button variant="primary" size="sm" @click="$dispatch('close-modal', 'quick-action-preview')">
                    {{ __('Got it') }}
                </x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</x-app-layout>

