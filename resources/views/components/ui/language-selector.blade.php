@php
    $currentLocale = app()->getLocale();
    $languages = [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'code' => 'EN',
            'dir' => 'ltr',
        ],
        'fa' => [
            'name' => 'Persian/Dari',
            'native' => 'فارسی / دری',
            'code' => 'فا',
            'dir' => 'rtl',
        ],
    ];
@endphp

<div
    x-data="{ open: false }"
    class="relative inline-block text-start"
    {{ $attributes }}
>
    <button
        type="button"
        @click="open = !open"
        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900 focus:outline-hidden dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white cursor-pointer"
        aria-haspopup="true"
        :aria-expanded="open"
        aria-label="{{ __('Select language') }}"
    >
        <x-icons.icon name="globe" class="size-4 text-slate-500 dark:text-slate-400" />
        <span>{{ $languages[$currentLocale]['native'] ?? 'English' }}</span>
        <x-icons.icon name="chevron-down" class="size-3 text-slate-400 transition-transform duration-150" ::class="open ? 'rotate-180' : ''" />
    </button>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute {{ $currentLocale === 'fa' ? 'left-0' : 'right-0' }} z-50 mt-1.5 w-44 origin-top rounded-xl border border-slate-200 bg-white py-1.5 shadow-lg ring-1 ring-black/5 dark:border-slate-800 dark:bg-slate-900 dark:ring-white/10"
        role="menu"
    >
        @foreach ($languages as $localeKey => $lang)
            <form method="POST" action="{{ route('locale.set') }}" class="block">
                @csrf
                <input type="hidden" name="locale" value="{{ $localeKey }}">
                <button
                    type="submit"
                    role="menuitem"
                    class="flex w-full items-center justify-between px-3.5 py-2 text-xs font-medium transition cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 {{ $currentLocale === $localeKey ? 'text-crimson-600 font-semibold bg-crimson-50/50 dark:bg-crimson-950/40 dark:text-crimson-400' : 'text-slate-700 dark:text-slate-300' }}"
                >
                    <span class="flex items-center gap-2">
                        <span>{{ $lang['native'] }}</span>
                        @if ($localeKey === 'fa')
                            <span class="text-[10px] text-slate-400">({{ $lang['name'] }})</span>
                        @endif
                    </span>
                    @if ($currentLocale === $localeKey)
                        <x-icons.icon name="check" class="size-3.5 text-crimson-600 dark:text-crimson-400" />
                    @endif
                </button>
            </form>
        @endforeach
    </div>
</div>

