<x-guest-layout title="Register" :minimal="true">
    <div class="min-h-screen flex flex-col justify-between bg-slate-50 px-4 py-8 sm:px-6 lg:px-8 dark:bg-slate-950">
        <div class="mx-auto flex w-full max-w-lg items-center justify-between">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                <span class="flex size-8 items-center justify-center rounded-lg bg-crimson-600 text-white">
                    <x-icons.icon name="droplet" class="size-4" />
                </span>
                <span>{{ config('app.name', 'BDMS') }}</span>
            </a>

            <x-ui.theme-toggle />
        </div>

        <div class="mx-auto w-full max-w-lg py-8">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Create your BDMS account
                </h1>
                <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-400">
                    Register as a blood donor or an authorized hospital requester.
                </p>
            </div>

            <x-ui.card>
                <form
                    method="POST"
                    action="{{ Route::has('register.attempt') ? route('register.attempt') : route('register') }}"
                    x-data="{ role: '{{ old('role', 'donor') }}', frontendNotice: false }"
                    @if (! Route::has('register.attempt'))
                        @submit.prevent="frontendNotice = true"
                    @endif
                    class="space-y-4"
                >
                    @csrf

                    @if (! Route::has('register.attempt'))
                        <div x-show="frontendNotice" x-cloak>
                            <x-ui.alert variant="info" :dismissible="false">
                                Frontend UI mode — registration controller &amp; database role fields will be connected when backend auth is added.
                            </x-ui.alert>
                        </div>
                    @endif

                    <div class="space-y-1.5">
                        <span class="block text-sm font-medium text-slate-700 dark:text-slate-200">Account type</span>
                        <div class="grid grid-cols-2 gap-2.5">
                            <label
                                @click="role = 'donor'"
                                :class="role === 'donor' ? 'border-crimson-600 bg-crimson-50/70 text-crimson-700 dark:border-crimson-500 dark:bg-crimson-950/50 dark:text-crimson-300' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300'"
                                class="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-medium cursor-pointer transition"
                            >
                                <input type="radio" name="role" value="donor" x-model="role" class="sr-only">
                                <x-icons.icon name="heart-pulse" class="size-4" />
                                <span>Blood Donor</span>
                            </label>

                            <label
                                @click="role = 'hospital'"
                                :class="role === 'hospital' ? 'border-crimson-600 bg-crimson-50/70 text-crimson-700 dark:border-crimson-500 dark:bg-crimson-950/50 dark:text-crimson-300' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300'"
                                class="flex items-center gap-2 rounded-lg border px-3.5 py-2.5 text-sm font-medium cursor-pointer transition"
                            >
                                <input type="radio" name="role" value="hospital" x-model="role" class="sr-only">
                                <x-icons.icon name="building-hospital" class="size-4" />
                                <span>Hospital Requester</span>
                            </label>
                        </div>
                    </div>

                    <x-ui.input
                        label="Full name"
                        name="name"
                        icon="user"
                        placeholder="Dr. Sarah Jenkins"
                        :value="old('name')"
                        required
                    />

                    <x-ui.input
                        label="Email address"
                        name="email"
                        type="email"
                        icon="envelope"
                        placeholder="name@example.com"
                        :value="old('email')"
                        required
                    />

                    {{-- Donor-specific fields --}}
                    <div x-show="role === 'donor'" class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select
                            label="Blood group"
                            name="blood_group"
                            placeholder="Select blood group"
                            :options="[
                                'A+' => 'A+',
                                'A-' => 'A-',
                                'B+' => 'B+',
                                'B-' => 'B-',
                                'AB+' => 'AB+',
                                'AB-' => 'AB-',
                                'O+' => 'O+',
                                'O-' => 'O-',
                            ]"
                        />

                        <x-ui.input
                            label="Date of birth"
                            name="date_of_birth"
                            type="date"
                            :value="old('date_of_birth')"
                        />
                    </div>

                    {{-- Hospital-specific fields --}}
                    <div x-show="role === 'hospital'" x-cloak class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input
                            label="Hospital / Clinic name"
                            name="organization_name"
                            icon="building-hospital"
                            placeholder="City General Hospital"
                            :value="old('organization_name')"
                        />

                        <x-ui.input
                            label="License / Facility code"
                            name="facility_code"
                            placeholder="HSP-2049"
                            :value="old('facility_code')"
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input
                            label="Password"
                            name="password"
                            type="password"
                            icon="lock-closed"
                            placeholder="Min. 8 characters"
                            required
                        />

                        <x-ui.input
                            label="Confirm password"
                            name="password_confirmation"
                            type="password"
                            icon="lock-closed"
                            placeholder="Repeat password"
                            required
                        />
                    </div>

                    <x-ui.button type="submit" variant="primary" class="w-full">
                        Create Account
                    </x-ui.button>
                </form>
            </x-ui.card>

            <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
                Already registered?
                <a href="{{ route('login') }}" class="font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                    Sign in
                </a>
            </p>
        </div>

        <p class="text-center text-xs text-slate-400 dark:text-slate-500">
            Staff accounts are provisioned by Blood Bank Administrators.
        </p>
    </div>
</x-guest-layout>

