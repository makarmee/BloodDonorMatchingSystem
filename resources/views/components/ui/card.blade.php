@props([
    'padding' => true,
])

<div {{ $attributes->merge(['class' => 'bg-white dark:bg-slate-900 border border-slate-200/90 dark:border-slate-800 rounded-xl shadow-xs']) }}>
    @isset($header)
        <div class="px-5 py-4 border-b border-slate-200/80 dark:border-slate-800">
            {{ $header }}
        </div>
    @endisset

    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="px-5 py-3.5 bg-slate-50/70 dark:bg-slate-950/50 border-t border-slate-200/80 dark:border-slate-800 rounded-b-xl">
            {{ $footer }}
        </div>
    @endisset
</div>

