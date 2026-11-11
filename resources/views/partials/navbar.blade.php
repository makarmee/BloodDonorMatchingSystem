<header
    x-data="{ mobileOpen: false }"
    class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-md dark:border-slate-800/80 dark:bg-slate-950/90"
>
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5">
            <span class="flex size-9 items-center justify-center rounded-xl bg-crimson-600 text-white shadow-xs">
                <x-icons.icon name="droplet" class="size-5" />
            </span>
            <div class="leading-tight">
                <span class="block text-base font-bold tracking-tight text-slate-900 dark:text-white">
                    {{ config('app.name', 'BDMS') }}
                </span>
                <span class="block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                    Blood Donation Network
                </span>
            </div>
        </a>

        <nav class="hidden md:flex items-center gap-6 text-sm font-medium text-slate-600 dark:text-slate-300" aria-label="Main navigation">
            <a href="{{ route('home') }}#blood-stock" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">Blood Stock</a>
            <a href="{{ route('home') }}#portals" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">Portals</a>
            <a href="{{ route('home') }}#camps" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">Donation Camps</a>
            <a href="{{ route('dashboard') }}" class="hover:text-crimson-600 dark:hover:text-crimson-400 transition">Dashboard Preview</a>
        </nav>

        <div class="hidden md:flex items-center gap-2.5">
            <x-ui.theme-toggle />

            @auth
                <x-ui.button :href="route('dashboard')" variant="primary" size="sm">
                    Dashboard
                </x-ui.button>
            @else
                <x-ui.button :href="route('login')" variant="ghost" size="sm">
                    Sign in
                </x-ui.button>
                <x-ui.button :href="route('register')" variant="primary" size="sm">
                    Become a Donor
                </x-ui.button>
            @endauth
        </div>

        <div class="flex items-center gap-2 md:hidden">
            <x-ui.theme-toggle />
            <button
                type="button"
                @click="mobileOpen = !mobileOpen"
                class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 cursor-pointer"
                :aria-expanded="mobileOpen"
                aria-label="Toggle navigation menu"
            >
                <span x-show="!mobileOpen">
                    <x-icons.icon name="menu" class="size-5" />
                </span>
                <span x-show="mobileOpen" x-cloak>
                    <x-icons.icon name="x-mark" class="size-5" />
                </span>
            </button>
        </div>
    </div>

    <div
        x-show="mobileOpen"
        x-cloak
        x-transition
        class="border-t border-slate-200 bg-white px-4 py-4 md:hidden dark:border-slate-800 dark:bg-slate-950"
    >
        <nav class="flex flex-col gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
            <a href="{{ route('home') }}#blood-stock" @click="mobileOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-900">Blood Stock</a>
            <a href="{{ route('home') }}#portals" @click="mobileOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-900">Portals</a>
            <a href="{{ route('home') }}#camps" @click="mobileOpen = false" class="rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-900">Donation Camps</a>
            <a href="{{ route('dashboard') }}" class="rounded-lg px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-900">Dashboard Preview</a>
        </nav>

        <div class="mt-4 flex flex-col gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
            <x-ui.button :href="route('login')" variant="outline" class="w-full">Sign in</x-ui.button>
            <x-ui.button :href="route('register')" variant="primary" class="w-full">Become a Donor</x-ui.button>
        </div>
    </div>
</header>

