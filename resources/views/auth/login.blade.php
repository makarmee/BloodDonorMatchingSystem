<x-guest-layout title="Sign in" :minimal="true">
    <div class="min-h-screen grid lg:grid-cols-2">
        {{-- Left branding panel --}}
        <div class="hidden lg:flex flex-col justify-between bg-slate-900 p-10 text-white relative overflow-hidden">
            <div class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-crimson-600/20 blur-3xl"></div>

            <a href="{{ route('home') }}" class="relative flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-xl bg-crimson-600 text-white">
                    <x-icons.icon name="droplet" class="size-5" />
                </span>
                <div>
                    <span class="block text-base font-bold tracking-tight">{{ config('app.name', 'BDMS') }}</span>
                    <span class="block text-xs text-slate-400">Blood Donation Management System</span>
                </div>
            </a>

            <div class="relative space-y-5 max-w-md">
                <x-ui.badge variant="crimson" :dot="true">Unified Clinical Access</x-ui.badge>
                <h1 class="text-3xl font-bold tracking-tight">
                    Every unit tracked. Every emergency prioritized.
                </h1>
                <p class="text-sm leading-relaxed text-slate-300">
                    Sign in to manage blood bank inventory, track hospital dispatch requests, or schedule your next life-saving donation.
                </p>
            </div>

            <div class="relative flex items-center gap-6 text-xs text-slate-400">
                <span>Role-aware portals: Staff • Donor • Hospital</span>
            </div>
        </div>

        {{-- Right login form --}}
        <div class="flex flex-col justify-between px-4 py-8 sm:px-8 lg:px-12 bg-slate-50 dark:bg-slate-950">
            <div class="flex items-center justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-crimson-600 text-white lg:hidden">
                        <x-icons.icon name="droplet" class="size-4" />
                    </span>
                    <span>&larr; Back to Home</span>
                </a>
                <x-ui.theme-toggle />
            </div>

            <div class="mx-auto w-full max-w-md py-10">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Sign in to BDMS</h2>
                    <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-400">
                        Access your Blood Bank Staff, Donor, or Hospital Requester account.
                    </p>
                </div>

                <x-ui.card>
                    <form
                        method="POST"
                        action="{{ Route::has('login.attempt') ? route('login.attempt') : route('login') }}"
                        @if (! Route::has('login.attempt'))
                            x-data="{ frontendNotice: false }"
                            @submit.prevent="frontendNotice = true"
                        @endif
                        class="space-y-4"
                    >
                        @csrf

                        @if (! Route::has('login.attempt'))
                            <div x-show="frontendNotice" x-cloak>
                                <x-ui.alert variant="info" :dismissible="false">
                                    Backend authentication routes are not wired yet. Use the portal preview links below to inspect the authenticated dashboard views.
                                </x-ui.alert>
                            </div>
                        @endif

                        <x-ui.input
                            label="Email address"
                            name="email"
                            type="email"
                            icon="envelope"
                            placeholder="name@hospital.org"
                            :value="old('email')"
                            required
                            autofocus
                        />

                        <x-ui.input
                            label="Password"
                            name="password"
                            type="password"
                            icon="lock-closed"
                            placeholder="Enter your password"
                            required
                        />

                        <div class="flex items-center justify-between text-xs">
                            <label class="inline-flex items-center gap-2 text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" name="remember" class="rounded border-slate-300 text-crimson-600 focus:ring-crimson-500 dark:border-slate-700 dark:bg-slate-900">
                                <span>Remember me</span>
                            </label>

                            <a href="{{ route('password.request') }}" class="font-medium text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                                Forgot password?
                            </a>
                        </div>

                        <x-ui.button type="submit" variant="primary" class="w-full">
                            Sign in
                        </x-ui.button>
                    </form>

                    <x-slot:footer>
                        <div class="space-y-2.5 text-center">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                                Quick Frontend Role Previews
                            </p>
                            <div class="grid grid-cols-3 gap-2">
                                <x-ui.button :href="route('dashboard', ['role' => 'staff'])" variant="outline" size="sm">Staff</x-ui.button>
                                <x-ui.button :href="route('dashboard', ['role' => 'donor'])" variant="outline" size="sm">Donor</x-ui.button>
                                <x-ui.button :href="route('dashboard', ['role' => 'hospital'])" variant="outline" size="sm">Hospital</x-ui.button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-ui.card>

                <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
                    New donor or partner hospital?
                    <a href="{{ route('register') }}" class="font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                        Create an account
                    </a>
                </p>
            </div>

            <p class="text-center text-xs text-slate-400 dark:text-slate-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'BDMS') }}
            </p>
        </div>
    </div>
</x-guest-layout>

