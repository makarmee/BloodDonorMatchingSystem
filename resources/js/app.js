import Alpine from 'alpinejs';

const THEME_STORAGE_KEY = 'bdms-theme';

function resolveInitialDarkState() {
    const saved = localStorage.getItem(THEME_STORAGE_KEY);

    if (saved === 'dark') {
        return true;
    }

    if (saved === 'light') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyThemeClass(isDark) {
    document.documentElement.classList.toggle('dark', isDark);
}

document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        dark: resolveInitialDarkState(),

        init() {
            applyThemeClass(this.dark);
        },

        toggle() {
            this.set(this.dark ? 'light' : 'dark');
        },

        set(mode) {
            this.dark = mode === 'dark';
            localStorage.setItem(THEME_STORAGE_KEY, mode);
            applyThemeClass(this.dark);
        },
    });
});

window.Alpine = Alpine;
Alpine.start();
