import Alpine from 'alpinejs';
import { campDetails, campForm, campRegistration, campsDirectory } from './modules/camps';
import { donationForm, donationsDirectory } from './modules/donations';
import {
    appointmentForm,
    donationHistory,
    donorAppointments,
    donorDashboard,
    donorEligibility,
} from './modules/donor-portal';
import { donorForm, donorsDirectory } from './modules/donors';
import { inventoryManager } from './modules/inventory';
import { publicBloodStock, publicCampsDirectory } from './modules/public-portal';
import { requestForm, requestFulfillment, requestsDirectory } from './modules/requests';
import { screeningDirectory, screeningForm } from './modules/screening';

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

    Alpine.data('inventoryManager', inventoryManager);
    Alpine.data('donorsDirectory', donorsDirectory);
    Alpine.data('donorForm', donorForm);
    Alpine.data('donationsDirectory', donationsDirectory);
    Alpine.data('donationForm', donationForm);
    Alpine.data('screeningDirectory', screeningDirectory);
    Alpine.data('screeningForm', screeningForm);
    Alpine.data('requestsDirectory', requestsDirectory);
    Alpine.data('requestForm', requestForm);
    Alpine.data('requestFulfillment', requestFulfillment);
    Alpine.data('campsDirectory', campsDirectory);
    Alpine.data('campForm', campForm);
    Alpine.data('campDetails', campDetails);
    Alpine.data('campRegistration', campRegistration);
    Alpine.data('donorDashboard', donorDashboard);
    Alpine.data('donorAppointments', donorAppointments);
    Alpine.data('appointmentForm', appointmentForm);
    Alpine.data('donationHistory', donationHistory);
    Alpine.data('donorEligibility', donorEligibility);
    Alpine.data('publicBloodStock', publicBloodStock);
    Alpine.data('publicCampsDirectory', publicCampsDirectory);

    Alpine.magic('num', () => (value) => window.toPersianDigits(value));
    Alpine.directive('num', (el, { expression }, { evaluateLater, effect }) => {
        const getValue = evaluateLater(expression);
        effect(() => {
            getValue((val) => {
                el.textContent = window.toPersianDigits(val);
            });
        });
    });
});

const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

window.isNumberExempt = function (str) {
    if (!str) return false;
    const trimmed = String(str).trim();
    if (/^(REQ|HSP|DON|USR|APT|MED|CAMP|SMP|UNT|BAG)-/i.test(trimmed)) return true;
    if (/^(A|B|AB|O)[+-]$/i.test(trimmed) || /^(PRBC|FFP|PLT|CRYO|WHOLE_BLOOD)$/i.test(trimmed)) return true;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) return true;
    if (/^\+?[0-9\s\-()]{7,25}$/.test(trimmed) && /[\s\-()+]/.test(trimmed)) return true;
    if (/^https?:\/\//i.test(trimmed)) return true;
    return false;
};

window.toPersianDigits = function (value) {
    if (value === null || value === undefined) {
        return '';
    }
    const str = String(value);
    if (window.BDMS_LOCALE !== 'fa') {
        return str;
    }
    if (window.isNumberExempt(str)) {
        return str;
    }
    return str.replace(/[0-9]/g, (digit) => PERSIAN_DIGITS[Number(digit)]);
};

function localizeTabularNums(root = document) {
    if (window.BDMS_LOCALE !== 'fa' || !root) return;
    const elements = root.querySelectorAll('.tabular-nums:not([dir="ltr"]):not(.dir-ltr):not(.font-mono)');
    elements.forEach((el) => {
        if (el.closest('[dir="ltr"], .dir-ltr, .font-mono, code, pre, input, textarea, select')) return;
        if (el.children.length === 0 && el.textContent) {
            const text = el.textContent;
            if (/[0-9]/.test(text) && !window.isNumberExempt(text)) {
                el.textContent = window.toPersianDigits(text);
            }
        }
    });
}

window.Alpine = Alpine;
window.__ = function (key, replace = {}) {
    let translation = window.BDMS_I18N?.[key] || key;
    for (const [placeholder, value] of Object.entries(replace)) {
        const displayValue = (window.BDMS_LOCALE === 'fa' && typeof value === 'number')
            ? window.toPersianDigits(value)
            : value;
        translation = translation.replace(`:${placeholder}`, displayValue);
    }
    return translation;
};

Alpine.start();

if (window.BDMS_LOCALE === 'fa') {
    document.addEventListener('DOMContentLoaded', () => {
        localizeTabularNums(document.body);

        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                    const target = mutation.target.nodeType === Node.TEXT_NODE
                        ? mutation.target.parentElement
                        : mutation.target;

                    if (!target || !(target instanceof HTMLElement)) continue;

                    if (target.classList?.contains('tabular-nums') || target.hasAttribute('data-persian-num')) {
                        if (target.closest('[dir="ltr"], .dir-ltr, .font-mono, code, pre, input, textarea, select')) continue;
                        if (target.children.length === 0 && /[0-9]/.test(target.textContent)) {
                            if (!window.isNumberExempt(target.textContent)) {
                                const converted = window.toPersianDigits(target.textContent);
                                if (converted !== target.textContent) {
                                    target.textContent = converted;
                                }
                            }
                        }
                    }
                }
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
        });
    });
}
