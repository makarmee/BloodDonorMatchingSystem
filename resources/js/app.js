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
});

window.Alpine = Alpine;
Alpine.start();
