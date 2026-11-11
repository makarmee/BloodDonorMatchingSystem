<button
    type="button"
    x-data
    @click="$store.theme.toggle()"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white cursor-pointer']) }}
    :aria-label="$store.theme.dark ? 'Switch to light mode' : 'Switch to dark mode'"
    :title="$store.theme.dark ? 'Switch to light mode' : 'Switch to dark mode'"
>
    <span x-show="!$store.theme.dark">
        <x-icons.icon name="moon" class="size-4" />
    </span>
    <span x-show="$store.theme.dark" x-cloak>
        <x-icons.icon name="sun" class="size-4 text-amber-400" />
    </span>
</button>

