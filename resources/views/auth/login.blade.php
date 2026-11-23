<x-guest-layout :title="__('Sign in')" :minimal="true">
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
                    <span class="block text-xs text-slate-400">{{ __('Blood Donation Management System') }}</span>
                </div>
            </a>

            <div class="relative space-y-5 max-w-md">
                <x-ui.badge variant="crimson" :dot="true">{{ __('Unified Clinical Access') }}</x-ui.badge>
                <h1 class="text-3xl font-bold tracking-tight">
                    {{ __('Every unit tracked. Every emergency prioritized.') }}
                </h1>
                <p class="text-sm leading-relaxed text-slate-300">
                    {{ __('Sign in to manage blood bank inventory, track hospital dispatch requests, or schedule your next life-saving donation.') }}
                </p>
            </div>

            <div class="relative flex items-center gap-6 text-xs text-slate-400">
                <span>{{ __('Role-aware portals: Admin • Staff • Lab Officer • Hospital • Donor') }}</span>
            </div>
        </div>

        {{-- Right login form --}}
        <div class="flex flex-col justify-between px-4 py-8 sm:px-8 lg:px-12 bg-slate-50 dark:bg-slate-950">
            <div class="flex items-center justify-between">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                    <x-icons.icon name="arrow-left" class="size-4" />
                    <span>{{ __('Back to Home') }}</span>
                </a>
                <div class="flex items-center gap-2">
                    <x-ui.language-selector />
                    <x-ui.theme-toggle />
                </div>
            </div>

            <div class="mx-auto w-full max-w-md py-10">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ __('Sign in to BDMS') }}</h2>
                    <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-400">
                        {{ __('Access your Administrator, Blood Bank Staff, Lab Officer, Hospital Partner, or Donor account.') }}
                    </p>
                </div>

                <x-ui.card>
                    <form
                        method="POST"
                        action="{{ route('login.attempt') }}"
                        class="space-y-4"
                    >
                        @csrf

                        <x-ui.input
                            :label="__('Email address')"
                            name="email"
                            type="email"
                            icon="envelope"
                            placeholder="name@hospital.org"
                            :value="old('email')"
                            required
                            autofocus
                        />

                        <x-ui.input
                            :label="__('Password')"
                            name="password"
                            type="password"
                            icon="lock-closed"
                            :placeholder="__('Enter your password')"
                            required
                        />

                        <div class="flex items-center justify-between text-xs">
                            <label class="inline-flex items-center gap-2 text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" name="remember" class="rounded border-slate-300 text-crimson-600 focus:ring-crimson-500 dark:border-slate-700 dark:bg-slate-900">
                                <span>{{ __('Remember me') }}</span>
                            </label>

                            <a href="{{ route('password.request') }}" class="font-medium text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                                {{ __('Forgot password?') }}
                            </a>
                        </div>

                        <x-ui.button type="submit" variant="primary" class="w-full">
                            {{ __('Sign in') }}
                        </x-ui.button>
                    </form>
                </x-ui.card>

                <p class="mt-6 text-center text-sm text-slate-600 dark:text-slate-400">
                    {{ __('New donor or partner hospital?') }}
                    <a href="{{ route('register') }}" class="font-semibold text-crimson-600 hover:text-crimson-700 dark:text-crimson-400">
                        {{ __('Create an account') }}
                    </a>
                </p>
            </div>

            <p class="text-center text-xs text-slate-400 dark:text-slate-500">
                &copy; {{ date('Y') }} {{ config('app.name', 'BDMS') }}
            </p>
        </div>
    </div>
</x-guest-layout>

