export function getTodayLocalIso() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function storageKey(donorId) {
    return `bdms-donor-portal-appointments:${donorId || 'DNR-1041'}`;
}

export function mergePreviewAppointments(donorId, serverAppointments = []) {
    const fallback = Array.isArray(serverAppointments) ? serverAppointments.map((item) => ({ ...item })) : [];

    try {
        if (typeof window === 'undefined' || !window.sessionStorage) {
            return fallback;
        }

        const raw = window.sessionStorage.getItem(storageKey(donorId));
        if (!raw) {
            return fallback;
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || parsed.length === 0) {
            return fallback;
        }

        return parsed;
    } catch {
        return fallback;
    }
}

export function savePreviewAppointments(donorId, appointments = []) {
    try {
        if (typeof window !== 'undefined' && window.sessionStorage) {
            window.sessionStorage.setItem(storageKey(donorId), JSON.stringify(appointments));
        }
    } catch {
        // Ignore storage quota or private browsing errors in preview mode.
    }
}

/**
 * Eligibility Rule 1-8: Strictly preserve explicit sample status without calculating medical clearance.
 */
export function normalizeEligibilityRecord(raw = {}) {
    const allowed = ['Eligible', 'Not Eligible', 'Pending Review', 'Unknown'];
    const rawStatus = typeof raw?.status === 'string' ? raw.status.trim() : '';
    const status = allowed.includes(rawStatus) ? rawStatus : 'Unknown';

    const fallbackReason =
        'Eligibility information is unavailable in sample records. Confirmation by authorized medical staff is required.';

    return {
        status,
        lastReviewedDate: raw?.lastReviewedDate ?? null,
        nextEligibleDate: raw?.nextEligibleDate ?? null,
        reason: status === 'Unknown' ? fallbackReason : (raw?.reason || fallbackReason),
        medicalConfirmationRequired:
            raw?.medicalConfirmationRequired ||
            'Final donation eligibility must be confirmed on-site by authorized blood bank medical staff.',
        administrativeFacts: Array.isArray(raw?.administrativeFacts) ? raw.administrativeFacts : [],
    };
}

export function isUpcomingAppointment(appointment, todayIso = getTodayLocalIso()) {
    return (
        ['Pending', 'Confirmed'].includes(appointment?.status) &&
        String(appointment?.date ?? '') >= String(todayIso)
    );
}

export function countSlotOccupancy({
    slotTime,
    date,
    locationId,
    baseBooked = 0,
    appointments = [],
    baselineIds = [],
}) {
    const baselineSet = new Set(Array.isArray(baselineIds) ? baselineIds : []);
    let cancelledBaseline = 0;
    let newlyBookedActive = 0;

    for (const apt of Array.isArray(appointments) ? appointments : []) {
        if (apt.locationId !== locationId || apt.date !== date || apt.time !== slotTime) {
            continue;
        }

        if (baselineSet.has(apt.id)) {
            if (apt.status === 'Cancelled') {
                cancelledBaseline += 1;
            }
        } else if (apt.status !== 'Cancelled') {
            // Cancelled preview appointments do not consume capacity (Rule 7).
            newlyBookedActive += 1;
        }
    }

    return Math.max(0, Number(baseBooked || 0) - cancelledBaseline + newlyBookedActive);
}

export function inspectTimeSlots({
    timeSlotTemplates = [],
    date = '',
    locationId = '',
    appointments = [],
    baselineIds = [],
}) {
    return (Array.isArray(timeSlotTemplates) ? timeSlotTemplates : []).map((tpl) => {
        const capacity = Math.max(0, Number(tpl.capacity) || 0);
        const booked = countSlotOccupancy({
            slotTime: tpl.time,
            date,
            locationId,
            baseBooked: tpl.booked,
            appointments,
            baselineIds,
        });
        const remaining = Math.max(0, capacity - booked);

        return {
            time: tpl.time,
            booked,
            capacity,
            remaining,
            isFull: remaining === 0,
        };
    });
}

/**
 * Rule 5: Return only available sample time slots for booking selection.
 */
export function getAvailableTimeSlots(params) {
    return inspectTimeSlots(params).filter((slot) => slot.remaining > 0);
}

/**
 * Validate donor appointment booking against all 10 Appointment Business Rules.
 */
export function validateAppointmentBooking({
    donorId,
    donationType,
    targetType,
    targetId,
    camps = [],
    centers = [],
    date,
    time,
    appointments = [],
    timeSlotTemplates = [],
    baselineIds = [],
    todayIso = getTodayLocalIso(),
}) {
    const errors = {};

    if (!String(donationType ?? '').trim()) {
        errors.donationType = 'Select a donation type.';
    }

    if (!String(targetId ?? '').trim()) {
        errors.location = 'Select a donation camp or donation center.';
    }

    const cleanDate = String(date ?? '').trim();
    if (cleanDate === '') {
        errors.date = 'Select a preferred appointment date.';
    } else if (cleanDate < String(todayIso)) {
        errors.date = 'Past dates cannot be selected. Choose today or an upcoming date.';
    }

    const cleanTime = String(time ?? '').trim();
    if (cleanTime === '') {
        errors.time = 'Select an available sample time slot.';
    }

    if (targetType === 'camp' && targetId) {
        const camp = (Array.isArray(camps) ? camps : []).find((c) => c.id === targetId);
        if (!camp) {
            errors.location = 'Selected donation camp could not be found.';
        } else {
            if (camp.status === 'Completed' || camp.status === 'Cancelled' || camp.status === 'Draft') {
                errors.location = `Cannot book an appointment at a ${camp.status} camp.`;
            } else if (
                camp.status === 'Upcoming' &&
                camp.registrationDeadline &&
                String(todayIso) > String(camp.registrationDeadline)
            ) {
                errors.location = `Registration deadline (${camp.registrationDeadline}) for ${camp.name} has passed.`;
            } else if (Number(camp.availableSlots) <= 0) {
                errors.location = `Selected camp (${camp.name}) has reached maximum donor capacity.`;
            } else if (camp.status === 'Ongoing' && !camp.allowWalkIn) {
                errors.location = `${camp.name} is currently ongoing with walk-in booking disabled.`;
            }

            if (cleanDate && cleanDate >= String(todayIso)) {
                const start = String(camp.startDate ?? '');
                const end = String(camp.endDate ?? start);
                if (start && (cleanDate < start || cleanDate > end)) {
                    errors.date = `Date must fall within the camp schedule (${start} to ${end}).`;
                }
            }
        }
    } else if (targetType === 'center' && targetId) {
        const center = (Array.isArray(centers) ? centers : []).find((c) => c.id === targetId);
        if (!center) {
            errors.location = 'Selected donation center could not be found.';
        }
    }

    // Rule 1: Prevent duplicate active appointments for the same donor at the exact date and time.
    if (cleanDate && cleanTime) {
        const duplicate = (Array.isArray(appointments) ? appointments : []).find(
            (apt) =>
                apt.donorId === donorId &&
                apt.date === cleanDate &&
                apt.time === cleanTime &&
                apt.status !== 'Cancelled'
        );

        if (duplicate) {
            errors.time = `Duplicate booking blocked: you already have a ${duplicate.status} appointment (${duplicate.id}) on ${cleanDate} at ${cleanTime}.`;
        }
    }

    // Rule 6: Prevent booking when a selected time slot is full.
    if (cleanTime && targetId && cleanDate) {
        const slot = inspectTimeSlots({
            timeSlotTemplates,
            date: cleanDate,
            locationId: targetId,
            appointments,
            baselineIds,
        }).find((entry) => entry.time === cleanTime);

        if (!slot) {
            errors.time = 'Selected time slot is unavailable for booking.';
        } else if (slot.isFull && !errors.time) {
            errors.time = `Time slot ${cleanTime} is currently full. Please select another available slot.`;
        }
    }

    const firstError = Object.values(errors)[0] ?? '';

    return {
        valid: Object.keys(errors).length === 0,
        errors,
        message: firstError,
    };
}

function appointmentBadgeClasses(status) {
    const map = {
        Pending: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
        Confirmed: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
        Completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
        Cancelled: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
    };
    return map[status] ?? map.Pending;
}

function eligibilityBadgeClasses(status) {
    const map = {
        Eligible: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
        'Not Eligible': 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
        'Pending Review': 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
        Unknown: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };
    return map[status] ?? map.Unknown;
}

export function donorDashboard(initial = {}) {
    const donorId = initial.donorId ?? 'DNR-1041';
    const initialAppointments = Array.isArray(initial.appointments) ? initial.appointments : [];

    return {
        donorId,
        todayIso: initial.todayIso ?? getTodayLocalIso(),
        appointments: [...initialAppointments],
        camps: Array.isArray(initial.camps) ? initial.camps : [],
        eligibility: normalizeEligibilityRecord(initial.eligibility ?? {}),
        selectedAppointment: null,
        cancelCandidate: null,
        selectedCamp: null,
        feedback: null,

        init() {
            this.appointments = mergePreviewAppointments(this.donorId, initialAppointments);
        },

        get upcomingAppointments() {
            return this.appointments
                .filter((apt) => isUpcomingAppointment(apt, this.todayIso))
                .sort((a, b) => String(a.date).localeCompare(String(b.date)));
        },

        get nextAppointment() {
            return this.upcomingAppointments[0] ?? null;
        },

        get upcomingCount() {
            return this.upcomingAppointments.length;
        },

        viewAppointment(appointment) {
            this.selectedAppointment = appointment;
            this.$dispatch('open-modal', 'donor-appointment-detail-modal');
        },

        promptCancel(appointment) {
            this.cancelCandidate = appointment;
            this.$dispatch('open-modal', 'donor-cancel-appointment-modal');
        },

        confirmCancel() {
            if (!this.cancelCandidate) {
                return;
            }

            const targetId = this.cancelCandidate.id;
            const label = `${this.cancelCandidate.dateFormatted} at ${this.cancelCandidate.time}`;

            this.appointments = this.appointments.map((apt) =>
                apt.id === targetId
                    ? { ...apt, status: 'Cancelled', notes: `${apt.notes ? `${apt.notes} ` : ''}(Cancelled in preview)` }
                    : apt
            );

            savePreviewAppointments(this.donorId, this.appointments);
            this.cancelCandidate = null;
            this.$dispatch('close-modal', 'donor-cancel-appointment-modal');

            this.feedback = {
                type: 'success',
                title: 'Appointment cancelled (Preview mode)',
                message: `Appointment ${targetId} (${label}) was marked Cancelled. Upcoming count updated and time slot released.`,
            };
        },

        viewCamp(camp) {
            this.selectedCamp = camp;
            this.$dispatch('open-modal', 'donor-camp-detail-modal');
        },

        statusBadgeClasses(status) {
            return appointmentBadgeClasses(status);
        },

        eligibilityBadgeClasses(status) {
            return eligibilityBadgeClasses(status);
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

export function donorAppointments(initial = {}) {
    const donorId = initial.donorId ?? 'DNR-1041';
    const initialAppointments = Array.isArray(initial.appointments) ? initial.appointments : [];

    return {
        donorId,
        todayIso: initial.todayIso ?? getTodayLocalIso(),
        appointments: [...initialAppointments],
        viewTab: 'upcoming',
        search: '',
        statusFilter: 'all',
        dateFrom: '',
        dateTo: '',
        selectedAppointment: null,
        cancelCandidate: null,
        feedback: null,

        init() {
            this.appointments = mergePreviewAppointments(this.donorId, initialAppointments);
        },

        get upcomingCount() {
            return this.appointments.filter((apt) => isUpcomingAppointment(apt, this.todayIso)).length;
        },

        get pastCount() {
            return this.appointments.filter((apt) => !isUpcomingAppointment(apt, this.todayIso)).length;
        },

        get filteredAppointments() {
            const query = this.search.trim().toLowerCase();

            return this.appointments
                .filter((apt) => {
                    const upcoming = isUpcomingAppointment(apt, this.todayIso);

                    if (this.viewTab === 'upcoming' && !upcoming) {
                        return false;
                    }
                    if (this.viewTab === 'past' && upcoming) {
                        return false;
                    }

                    const matchesSearch =
                        query === '' ||
                        String(apt.id ?? '').toLowerCase().includes(query) ||
                        String(apt.location ?? '').toLowerCase().includes(query) ||
                        String(apt.relatedCampName ?? '').toLowerCase().includes(query) ||
                        String(apt.donationType ?? '').toLowerCase().includes(query);

                    const matchesStatus =
                        this.statusFilter === 'all' ||
                        apt.status === this.statusFilter;

                    const matchesFrom =
                        this.dateFrom === '' ||
                        String(apt.date ?? '') >= this.dateFrom;

                    const matchesTo =
                        this.dateTo === '' ||
                        String(apt.date ?? '') <= this.dateTo;

                    return matchesSearch && matchesStatus && matchesFrom && matchesTo;
                })
                .sort((a, b) => {
                    if (this.viewTab === 'upcoming') {
                        return String(a.date).localeCompare(String(b.date));
                    }
                    return String(b.date).localeCompare(String(a.date));
                });
        },

        get hasActiveFilters() {
            return (
                this.search.trim() !== '' ||
                this.statusFilter !== 'all' ||
                this.dateFrom !== '' ||
                this.dateTo !== ''
            );
        },

        resetFilters() {
            this.search = '';
            this.statusFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
        },

        canCancel(appointment) {
            return ['Pending', 'Confirmed'].includes(appointment?.status);
        },

        viewDetails(appointment) {
            this.selectedAppointment = appointment;
            this.$dispatch('open-modal', 'donor-appointment-detail-modal');
        },

        promptCancel(appointment) {
            if (!this.canCancel(appointment)) {
                return;
            }
            this.cancelCandidate = appointment;
            this.$dispatch('open-modal', 'donor-cancel-appointment-modal');
        },

        confirmCancel() {
            if (!this.cancelCandidate) {
                return;
            }

            const targetId = this.cancelCandidate.id;

            this.appointments = this.appointments.map((apt) =>
                apt.id === targetId
                    ? { ...apt, status: 'Cancelled', notes: `${apt.notes ? `${apt.notes} ` : ''}(Cancelled by donor in preview)` }
                    : apt
            );

            savePreviewAppointments(this.donorId, this.appointments);
            this.cancelCandidate = null;
            this.$dispatch('close-modal', 'donor-cancel-appointment-modal');

            this.feedback = {
                type: 'success',
                title: 'Appointment cancelled (Preview mode)',
                message: `Appointment ${targetId} has been cancelled and moved out of your upcoming schedule. Completed and past records remain archived.`,
            };
        },

        statusBadgeClasses(status) {
            return appointmentBadgeClasses(status);
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

export function appointmentForm(initial = {}) {
    const donorId = initial.donorId ?? 'DNR-1041';
    const initialAppointments = Array.isArray(initial.initialAppointments) ? initial.initialAppointments : [];
    const baselineIds = initialAppointments.map((apt) => apt.id);
    const camps = Array.isArray(initial.camps) ? initial.camps : [];
    const centers = Array.isArray(initial.centers) ? initial.centers : [];
    const timeSlotTemplates = Array.isArray(initial.timeSlotTemplates) ? initial.timeSlotTemplates : [];
    const todayIso = initial.todayIso ?? getTodayLocalIso();

    const preCamp = initial.preselectedCampId
        ? camps.find((camp) => camp.id === initial.preselectedCampId)
        : null;

    const defaultLocationSelection = preCamp
        ? `camp:${preCamp.id}`
        : (centers[0] ? `center:${centers[0].id}` : '');

    const defaultDate = preCamp ? preCamp.startDate : '2026-11-19';

    return {
        donorId,
        todayIso,
        camps,
        centers,
        timeSlotTemplates,
        baselineIds,
        appointments: [...initialAppointments],
        form: {
            donationType: 'Whole Blood',
            locationSelection: defaultLocationSelection,
            date: defaultDate,
            time: '',
            notes: '',
        },
        errors: {},
        feedback: null,

        init() {
            this.appointments = mergePreviewAppointments(this.donorId, initialAppointments);
            this.syncDefaultTime();
        },

        get parsedTarget() {
            const raw = String(this.form.locationSelection ?? '');
            const [type, id] = raw.split(':');
            return {
                type: type === 'camp' ? 'camp' : (type === 'center' ? 'center' : ''),
                id: id ?? '',
            };
        },

        get selectedCamp() {
            if (this.parsedTarget.type !== 'camp') {
                return null;
            }
            return this.camps.find((camp) => camp.id === this.parsedTarget.id) ?? null;
        },

        get selectedCenter() {
            if (this.parsedTarget.type !== 'center') {
                return null;
            }
            return this.centers.find((center) => center.id === this.parsedTarget.id) ?? null;
        },

        get availableTimeSlots() {
            if (!this.parsedTarget.id || !this.form.date) {
                return [];
            }

            return getAvailableTimeSlots({
                timeSlotTemplates: this.timeSlotTemplates,
                date: this.form.date,
                locationId: this.parsedTarget.id,
                appointments: this.appointments,
                baselineIds: this.baselineIds,
            });
        },

        onLocationChange() {
            if (this.selectedCamp) {
                this.form.date = this.selectedCamp.startDate;
            }
            this.syncDefaultTime();
            this.validateCurrentSelection();
        },

        onDateChange() {
            this.syncDefaultTime();
            this.validateCurrentSelection();
        },

        syncDefaultTime() {
            const slots = this.availableTimeSlots;
            if (slots.length === 0) {
                this.form.time = '';
                return;
            }

            const activeTimes = new Set(slots.map((s) => s.time));
            if (!activeTimes.has(this.form.time)) {
                this.form.time = slots[0].time;
            }
        },

        validateCurrentSelection() {
            const result = validateAppointmentBooking({
                donorId: this.donorId,
                donationType: this.form.donationType,
                targetType: this.parsedTarget.type,
                targetId: this.parsedTarget.id,
                camps: this.camps,
                centers: this.centers,
                date: this.form.date,
                time: this.form.time,
                appointments: this.appointments,
                timeSlotTemplates: this.timeSlotTemplates,
                baselineIds: this.baselineIds,
                todayIso: this.todayIso,
            });

            this.errors = result.errors;
            return result;
        },

        submitForm() {
            const result = this.validateCurrentSelection();
            if (!result.valid) {
                this.feedback = {
                    type: 'danger',
                    title: 'Appointment validation error',
                    message: result.message || 'Review highlighted appointment details before submitting.',
                };
                if (typeof window !== 'undefined' && window.scrollTo) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
                return;
            }

            const newId = `APT-${2100 + this.appointments.length}`;
            const locationName = this.selectedCamp
                ? `${this.selectedCamp.venueName} (${this.selectedCamp.city})`
                : (this.selectedCenter?.name ?? 'Blood Bank Center');

            const newAppointment = {
                id: newId,
                donorId: this.donorId,
                donationType: this.form.donationType,
                date: this.form.date,
                dateFormatted: this.formatIsoDisplay(this.form.date),
                time: this.form.time,
                location: locationName,
                locationId: this.parsedTarget.id,
                relatedCampId: this.selectedCamp?.id ?? null,
                relatedCampName: this.selectedCamp?.name ?? null,
                status: 'Pending',
                notes: this.form.notes.trim() || 'Submitted via Donor Portal preview.',
                prepSummary: this.selectedCamp?.donorRequirements || 'Bring government photo ID and stay hydrated.',
            };

            this.appointments = [newAppointment, ...this.appointments];
            savePreviewAppointments(this.donorId, this.appointments);

            this.feedback = {
                type: 'success',
                title: 'Appointment booked (Preview mode)',
                message: `Reference ${newId} reserved for ${this.form.donationType} on ${newAppointment.dateFormatted} at ${this.form.time} (${locationName}). Your dashboard and appointments list have been updated.`,
            };

            this.form.notes = '';
            this.syncDefaultTime();

            if (typeof window !== 'undefined' && window.scrollTo) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },

        formatIsoDisplay(isoDate) {
            const parts = String(isoDate).split('-').map(Number);
            if (parts.length !== 3 || parts.some(Number.isNaN)) {
                return isoDate;
            }
            const utcDate = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
            return utcDate.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                timeZone: 'UTC',
            });
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

export function donationHistory(initial = {}) {
    return {
        donations: Array.isArray(initial.donations) ? initial.donations : [],
        search: '',
        statusFilter: 'all',
        dateFrom: '',
        dateTo: '',
        selectedDonation: null,

        get filteredDonations() {
            const query = this.search.trim().toLowerCase();

            return this.donations.filter((item) => {
                const matchesSearch =
                    query === '' ||
                    String(item.id ?? '').toLowerCase().includes(query) ||
                    String(item.collectionLocation ?? '').toLowerCase().includes(query);

                const matchesStatus =
                    this.statusFilter === 'all' ||
                    item.collectionStatus === this.statusFilter;

                const matchesFrom =
                    this.dateFrom === '' ||
                    String(item.donationDate ?? '') >= this.dateFrom;

                const matchesTo =
                    this.dateTo === '' ||
                    String(item.donationDate ?? '') <= this.dateTo;

                return matchesSearch && matchesStatus && matchesFrom && matchesTo;
            });
        },

        get hasActiveFilters() {
            return (
                this.search.trim() !== '' ||
                this.statusFilter !== 'all' ||
                this.dateFrom !== '' ||
                this.dateTo !== ''
            );
        },

        resetFilters() {
            this.search = '';
            this.statusFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
        },

        viewDonation(donation) {
            this.selectedDonation = donation;
            this.$dispatch('open-modal', 'donor-donation-detail-modal');
        },

        statusBadgeClasses(status) {
            const map = {
                Completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                Cleared: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                Passed: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                Scheduled: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                Pending: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                Cancelled: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
                Failed: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
            };
            return map[status] ?? map.Pending;
        },
    };
}

export function donorEligibility(initial = {}) {
    return {
        eligibility: normalizeEligibilityRecord(initial.eligibility ?? {}),

        badgeClasses(status) {
            return eligibilityBadgeClasses(status);
        },
    };
}

