<x-guest-layout title="Reset Password" :minimal="true">
    <div class="min-h-screen flex flex-col justify-between bg-slate-50 px-4 py-8 sm:px-6 lg:px-8 dark:bg-slate-950">
        <div class="mx-auto flex w-full max-w-md items-center justify-between">
            <a href="{{ route('login') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">
                &larr; Back to Sign in
            </a>
            <x-ui.theme-toggle />
        </div>

        <div class="mx-auto w-full max-w-md py-8">
            <div class="mb-6 text-center">
                <div class="mx-auto mb-3 flex size-11 items-center justify-center rounded-xl bg-crimson-50 text-crimson-600 dark:bg-crimson-950/70 dark:text-crimson-400">
                    <x-icons.icon name="lock-closed" class="size-5" />
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                    Forgot your password?
                </h1>
                <p class="mt-1.5 text-sm text-slate-600 dark:text-slate-400">
                    Enter your registered email and we will send a password reset link.
                </p>
            </div>

            <x-ui.card>
                <form
                    method="POST"
                    action="{{ Route::has('password.email') ? route('password.email') : route('password.request') }}"
                    @if (! Route::has('password.email'))
                        x-data="{ frontendNotice: false }"
                        @submit.prevent="frontendNotice = true"
                    @endif
                    class="space-y-4"
                >
                    @csrf

                    @if (! Route::has('password.email'))
                        <div x-show="frontendNotice" x-cloak>
                            <x-ui.alert variant="info" :dismissible="false">
                                Frontend UI mode — password reset mail dispatch will activate once auth routes are added.
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

                    <x-ui.button type="submit" variant="primary" class="w-full">
                        Send Reset Link
                    </x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <p class="text-center text-xs text-slate-400 dark:text-slate-500">
            &copy; {{ date('Y') }} {{ config('app.name', 'BDMS') }}
        </p>
    </div>
</x-guest-layout>

