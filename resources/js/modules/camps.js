export function getTodayLocalIso() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function countActiveRegistrations(registrations = []) {
    if (!Array.isArray(registrations)) {
        return 0;
    }

    return registrations.filter((reg) => reg.attendanceStatus !== 'Cancelled').length;
}

export function calculateAvailableSlots(capacity, activeRegistrationsCount) {
    const cap = Math.max(0, Number(capacity) || 0);
    const active = Math.max(0, Number(activeRegistrationsCount) || 0);
    return Math.max(0, cap - active);
}

/**
 * Centralized evaluation of Camp Registration Business Rules (Rules 1-8).
 */
export function evaluateRegistrationEligibility({
    campStatus,
    allowWalkIn,
    registrationDeadline,
    todayIso = getTodayLocalIso(),
    capacity,
    registrations = [],
    donorId = '',
}) {
    if (campStatus === 'Draft') {
        return {
            allowed: false,
            reason: 'Draft camps are not open for donor registration.',
        };
    }

    if (campStatus === 'Completed') {
        return {
            allowed: false,
            reason: 'Completed camps cannot accept new donor registrations.',
        };
    }

    if (campStatus === 'Cancelled') {
        return {
            allowed: false,
            reason: 'Cancelled camps cannot accept donor registrations.',
        };
    }

    const activeCount = countActiveRegistrations(registrations);
    const numericCapacity = Math.max(0, Number(capacity) || 0);

    if (activeCount >= numericCapacity) {
        return {
            allowed: false,
            reason: 'Maximum camp donor capacity has been reached (0 slots available).',
        };
    }

    if (campStatus === 'Ongoing' && !allowWalkIn) {
        return {
            allowed: false,
            reason: 'This ongoing camp has walk-in registrations disabled.',
        };
    }

    if (
        campStatus === 'Upcoming' &&
        registrationDeadline &&
        todayIso > registrationDeadline
    ) {
        return {
            allowed: false,
            reason: `The registration deadline (${registrationDeadline}) for this camp has passed.`,
        };
    }

    const trimmedDonorId = String(donorId ?? '').trim();
    if (
        trimmedDonorId !== '' &&
        Array.isArray(registrations) &&
        registrations.some((reg) => reg.donorId === trimmedDonorId)
    ) {
        return {
            allowed: false,
            reason: `Donor ${trimmedDonorId} is already registered for this camp.`,
        };
    }

    return {
        allowed: true,
        reason: campStatus === 'Ongoing'
            ? 'Walk-in registration enabled for ongoing camp.'
            : 'Camp is open for donor registration.',
    };
}

/**
 * Centralized schedule & capacity consistency validation for Create / Edit Camp forms.
 */
export function validateCampScheduleAndCapacity({
    startDate,
    endDate,
    startTime,
    endTime,
    capacity,
    registrationDeadline,
    existingRegistrationsCount = 0,
    isEdit = false,
}) {
    const errors = {};

    if (startDate && endDate && endDate < startDate) {
        errors.endDate = 'End date cannot be earlier than start date.';
    }

    if (
        startDate &&
        endDate &&
        startDate === endDate &&
        startTime &&
        endTime &&
        endTime <= startTime
    ) {
        errors.endTime = 'End time must be later than start time when both dates are the same.';
    }

    if (startDate && registrationDeadline && registrationDeadline > startDate) {
        errors.registrationDeadline = 'Registration deadline cannot be later than the camp start date.';
    }

    const numericCapacity = Number(capacity);
    if (String(capacity ?? '').trim() !== '' && Number.isFinite(numericCapacity)) {
        if (!Number.isInteger(numericCapacity) || numericCapacity < 1) {
            errors.capacity = 'Maximum donor capacity must be a positive integer.';
        } else if (isEdit && numericCapacity < existingRegistrationsCount) {
            errors.capacity = `Maximum capacity cannot be lower than existing active registrations (${existingRegistrationsCount}).`;
        }
    }

    return errors;
}

export function campsDirectory(initial = {}) {
    return {
        camps: Array.isArray(initial.camps) ? initial.camps : [],
        search: '',
        statusFilter: 'all',
        locationFilter: 'all',
        dateFrom: '',
        dateTo: '',
        currentPage: 1,
        perPage: 5,

        init() {
            const resetPage = () => {
                this.currentPage = 1;
            };

            this.$watch('search', resetPage);
            this.$watch('statusFilter', resetPage);
            this.$watch('locationFilter', resetPage);
            this.$watch('dateFrom', resetPage);
            this.$watch('dateTo', resetPage);
            this.$watch('perPage', resetPage);
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.camps.reduce((matches, camp, index) => {
                const id = String(camp.id ?? '').toLowerCase();
                const name = String(camp.name ?? '').toLowerCase();
                const startDate = String(camp.startDate ?? '');
                const endDate = String(camp.endDate ?? startDate);

                const matchesSearch =
                    query === '' ||
                    id.includes(query) ||
                    name.includes(query);

                const matchesStatus =
                    this.statusFilter === 'all' ||
                    camp.status === this.statusFilter;

                const matchesLocation =
                    this.locationFilter === 'all' ||
                    camp.city === this.locationFilter;

                const matchesDateFrom =
                    this.dateFrom === '' ||
                    (endDate !== '' && endDate >= this.dateFrom);

                const matchesDateTo =
                    this.dateTo === '' ||
                    (startDate !== '' && startDate <= this.dateTo);

                if (
                    matchesSearch &&
                    matchesStatus &&
                    matchesLocation &&
                    matchesDateFrom &&
                    matchesDateTo
                ) {
                    matches.push(index);
                }

                return matches;
            }, []);
        },

        get totalFiltered() {
            return this.filteredIndices.length;
        },

        get totalPages() {
            const size = Number(this.perPage) || 5;
            return Math.max(1, Math.ceil(this.totalFiltered / size));
        },

        get safePage() {
            return Math.min(Math.max(1, this.currentPage), this.totalPages);
        },

        get visibleIndices() {
            const size = Number(this.perPage) || 5;
            const start = (this.safePage - 1) * size;
            return this.filteredIndices.slice(start, start + size);
        },

        get showingFrom() {
            if (this.totalFiltered === 0) {
                return 0;
            }
            const size = Number(this.perPage) || 5;
            return (this.safePage - 1) * size + 1;
        },

        get showingTo() {
            if (this.totalFiltered === 0) {
                return 0;
            }
            const size = Number(this.perPage) || 5;
            return Math.min(this.safePage * size, this.totalFiltered);
        },

        get hasActiveFilters() {
            return (
                this.search.trim() !== '' ||
                this.statusFilter !== 'all' ||
                this.locationFilter !== 'all' ||
                this.dateFrom !== '' ||
                this.dateTo !== ''
            );
        },

        get pageNumbers() {
            return Array.from({ length: this.totalPages }, (_, idx) => idx + 1);
        },

        isRowVisible(index) {
            return this.visibleIndices.includes(index);
        },

        setPage(page) {
            const target = Number(page);
            if (!Number.isFinite(target)) {
                return;
            }
            this.currentPage = Math.min(Math.max(1, target), this.totalPages);
        },

        prevPage() {
            this.setPage(this.safePage - 1);
        },

        nextPage() {
            this.setPage(this.safePage + 1);
        },

        resetFilters() {
            this.search = '';
            this.statusFilter = 'all';
            this.locationFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
        },
    };
}

export function campForm(initial = {}) {
    const initialForm = {
        name: initial.form?.name ?? '',
        description: initial.form?.description ?? '',
        organizerName: initial.form?.organizerName ?? '',
        organizerContact: initial.form?.organizerContact ?? '',
        organizerEmail: initial.form?.organizerEmail ?? '',
        venueName: initial.form?.venueName ?? '',
        fullAddress: initial.form?.fullAddress ?? '',
        city: initial.form?.city ?? '',
        locationNotes: initial.form?.locationNotes ?? '',
        startDate: initial.form?.startDate ?? '',
        endDate: initial.form?.endDate ?? '',
        startTime: initial.form?.startTime ?? '',
        endTime: initial.form?.endTime ?? '',
        capacity: initial.form?.capacity ?? '',
        registrationDeadline: initial.form?.registrationDeadline ?? '',
        allowWalkIn: initial.form?.allowWalkIn ?? 'Yes',
        donorRequirements: initial.form?.donorRequirements ?? '',
        additionalNotes: initial.form?.additionalNotes ?? '',
    };

    return {
        mode: initial.mode === 'edit' ? 'edit' : 'create',
        campId: initial.campId ?? null,
        campStatus: initial.campStatus ?? 'Draft',
        existingRegistrationsCount: Number(initial.existingRegistrationsCount) || 0,
        snapshot: { ...initialForm },
        form: { ...initialForm },
        errors: {},
        feedback: null,

        get isClosedCamp() {
            return ['Completed', 'Cancelled'].includes(this.campStatus);
        },

        validateField(field) {
            const value = String(this.form[field] ?? '').trim();
            let error = '';

            if (field === 'name') {
                if (value === '') {
                    error = 'Camp name is required.';
                } else if (value.length < 3) {
                    error = 'Camp name must be at least 3 characters.';
                }
            } else if (field === 'description') {
                if (value === '') {
                    error = 'Camp description is required.';
                } else if (value.length < 10) {
                    error = 'Provide a short overview of at least 10 characters.';
                }
            } else if (field === 'organizerName') {
                if (value === '') {
                    error = 'Organizer name is required.';
                }
            } else if (field === 'organizerContact') {
                const digitsOnly = value.replace(/\D/g, '');
                if (value === '') {
                    error = 'Organizer contact number is required.';
                } else if (!/^\+?[0-9\s\-().]{7,20}$/.test(value) || digitsOnly.length < 7) {
                    error = 'Enter a valid organizer phone number (at least 7 digits).';
                }
            } else if (field === 'organizerEmail') {
                if (value === '') {
                    error = 'Organizer email is required.';
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    error = 'Enter a valid organizer email address.';
                }
            } else if (field === 'venueName') {
                if (value === '') {
                    error = 'Venue name is required.';
                }
            } else if (field === 'fullAddress') {
                if (value === '') {
                    error = 'Full venue address is required.';
                }
            } else if (field === 'city') {
                if (value === '') {
                    error = 'City is required.';
                }
            } else if (field === 'startDate') {
                if (value === '') {
                    error = 'Start date is required.';
                }
            } else if (field === 'endDate') {
                if (value === '') {
                    error = 'End date is required.';
                }
            } else if (field === 'startTime') {
                if (value === '') {
                    error = 'Start time is required.';
                }
            } else if (field === 'endTime') {
                if (value === '') {
                    error = 'End time is required.';
                }
            } else if (field === 'capacity') {
                const numeric = Number(value);
                if (value === '' || !Number.isFinite(numeric)) {
                    error = 'Maximum donor capacity is required.';
                } else if (!Number.isInteger(numeric) || numeric < 1) {
                    error = 'Maximum donor capacity must be a positive integer.';
                }
            } else if (field === 'registrationDeadline') {
                if (value === '') {
                    error = 'Registration deadline is required.';
                }
            } else if (field === 'allowWalkIn') {
                if (!['Yes', 'No'].includes(value)) {
                    error = 'Select Yes or No for walk-in donors.';
                }
            } else if (field === 'donorRequirements') {
                if (value === '') {
                    error = 'Donor requirements summary is required.';
                }
            }

            const nextErrors = { ...this.errors };
            if (error) {
                nextErrors[field] = error;
            } else {
                delete nextErrors[field];
            }

            const crossErrors = validateCampScheduleAndCapacity({
                startDate: this.form.startDate,
                endDate: this.form.endDate,
                startTime: this.form.startTime,
                endTime: this.form.endTime,
                capacity: this.form.capacity,
                registrationDeadline: this.form.registrationDeadline,
                existingRegistrationsCount: this.existingRegistrationsCount,
                isEdit: this.mode === 'edit',
            });

            if (crossErrors.endDate) {
                nextErrors.endDate = crossErrors.endDate;
            }
            if (crossErrors.endTime) {
                nextErrors.endTime = crossErrors.endTime;
            }
            if (crossErrors.registrationDeadline) {
                nextErrors.registrationDeadline = crossErrors.registrationDeadline;
            }
            if (crossErrors.capacity) {
                nextErrors.capacity = crossErrors.capacity;
            }

            this.errors = nextErrors;
            return !nextErrors[field];
        },

        validateAll() {
            const fields = [
                'name',
                'description',
                'organizerName',
                'organizerContact',
                'organizerEmail',
                'venueName',
                'fullAddress',
                'city',
                'startDate',
                'endDate',
                'startTime',
                'endTime',
                'capacity',
                'registrationDeadline',
                'allowWalkIn',
                'donorRequirements',
            ];

            this.errors = {};
            for (const field of fields) {
                this.validateField(field);
            }

            return Object.keys(this.errors).length === 0;
        },

        submitForm() {
            if (!this.validateAll()) {
                this.feedback = {
                    type: 'danger',
                    title: 'Validation errors found',
                    message: 'Please correct the highlighted camp schedule, contact, or capacity fields before submitting.',
                };
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            if (this.mode === 'edit') {
                const preservedNotice = this.existingRegistrationsCount > 0
                    ? ` Existing ${this.existingRegistrationsCount} active registration(s) remain preserved.`
                    : '';

                this.feedback = {
                    type: 'success',
                    title: 'Camp updates validated (Preview mode)',
                    message: `Changes to "${this.form.name}" (${this.campId}) passed schedule and capacity checks.${preservedNotice} No database records were modified.`,
                };
            } else {
                this.feedback = {
                    type: 'success',
                    title: 'Donation camp validated (Preview mode)',
                    message: `"${this.form.name}" (${this.form.city}, ${this.form.capacity} donor capacity, walk-in: ${this.form.allowWalkIn}) passed validation. Backend persistence will save this camp in the database phase.`,
                };
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        resetForm() {
            this.form = { ...this.snapshot };
            this.errors = {};
            this.feedback = null;
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

export function campDetails(initial = {}) {
    const camp = initial.camp ?? {};

    return {
        campId: camp.id ?? '',
        campName: camp.name ?? '',
        status: camp.status ?? 'Draft',
        capacity: Number(camp.capacity) || 0,
        allowWalkIn: Boolean(camp.allowWalkIn),
        registrationDeadline: camp.registrationDeadline ?? '',
        registrations: Array.isArray(camp.registrations) ? [...camp.registrations] : [],
        history: Array.isArray(camp.history) ? [...camp.history] : [],
        registrationSearch: '',
        attendanceFilter: 'all',
        feedback: null,

        init() {
            window.addEventListener('camp-registrations-changed', (event) => {
                if (event.detail?.campId !== this.campId) {
                    return;
                }
                this.registrations = [...(event.detail.registrations ?? [])];
                if (event.detail.historyEntry) {
                    this.history.unshift(event.detail.historyEntry);
                }
                if (event.detail.feedback) {
                    this.feedback = event.detail.feedback;
                }
            });
        },

        get activeRegistrationsCount() {
            return countActiveRegistrations(this.registrations);
        },

        get availableSlots() {
            return calculateAvailableSlots(this.capacity, this.activeRegistrationsCount);
        },

        get progressPercent() {
            if (this.capacity <= 0) {
                return 0;
            }
            return Math.min(100, Math.round((this.activeRegistrationsCount / this.capacity) * 100));
        },

        get filteredRegistrations() {
            const query = this.registrationSearch.trim().toLowerCase();

            return this.registrations.filter((reg) => {
                const matchesSearch =
                    query === '' ||
                    String(reg.id ?? '').toLowerCase().includes(query) ||
                    String(reg.donorName ?? '').toLowerCase().includes(query) ||
                    String(reg.donorId ?? '').toLowerCase().includes(query);

                const matchesStatus =
                    this.attendanceFilter === 'all' ||
                    reg.attendanceStatus === this.attendanceFilter;

                return matchesSearch && matchesStatus;
            });
        },

        statusBadgeClasses(status) {
            const map = {
                Draft: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
                Upcoming: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                Ongoing: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                Completed: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                Cancelled: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
            };
            return map[status] ?? map.Draft;
        },

        attendanceBadgeClasses(attendanceStatus) {
            const map = {
                Registered: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                Confirmed: 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/70 dark:text-crimson-300 dark:ring-crimson-800/70',
                Attended: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                'No-show': 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                Cancelled: 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
            };
            return map[attendanceStatus] ?? map.Registered;
        },

        updateAttendanceStatus(regId, nextStatus) {
            const index = this.registrations.findIndex((item) => item.id === regId);
            if (index === -1) {
                return;
            }

            const current = this.registrations[index];
            const previousStatus = current.attendanceStatus;
            if (previousStatus === nextStatus) {
                return;
            }

            // Re-activating a cancelled registration must never exceed camp capacity
            if (
                previousStatus === 'Cancelled' &&
                nextStatus !== 'Cancelled' &&
                this.activeRegistrationsCount >= this.capacity
            ) {
                this.feedback = {
                    type: 'danger',
                    title: 'Capacity limit reached',
                    message: `Cannot reactivate ${current.donorName} (${current.id}): camp is already at ${this.capacity}/${this.capacity} active capacity.`,
                };
                return;
            }

            const updatedList = this.registrations.map((item, idx) =>
                idx === index ? { ...item, attendanceStatus: nextStatus } : item
            );
            this.registrations = updatedList;

            this.history.unshift({
                date: 'Preview Update',
                event: 'Attendance Updated',
                actor: `${current.donorName} (${current.donorId})`,
                notes: `Attendance changed from ${previousStatus} to ${nextStatus} (preview only).`,
            });

            window.dispatchEvent(new CustomEvent('camp-state-synced', {
                detail: {
                    campId: this.campId,
                    campStatus: this.status,
                    registrations: updatedList,
                },
            }));

            this.feedback = {
                type: 'success',
                title: 'Attendance status updated (Preview)',
                message: `${current.donorName} (${current.id}) marked as ${nextStatus}. Active registrations: ${this.activeRegistrationsCount}/${this.capacity}.`,
            };
        },

        changeCampStatus(nextStatus) {
            if (this.status === nextStatus) {
                return;
            }

            const previousStatus = this.status;
            this.status = nextStatus;

            // Rule 10: Completing or cancelling a camp must never delete registration history
            this.history.unshift({
                date: 'Preview Update',
                event: `Camp Marked ${nextStatus}`,
                actor: 'Blood Bank Staff (Preview)',
                notes: `Status changed from ${previousStatus} to ${nextStatus}. All ${this.registrations.length} registration records preserved.`,
            });

            window.dispatchEvent(new CustomEvent('camp-state-synced', {
                detail: {
                    campId: this.campId,
                    campStatus: this.status,
                    registrations: this.registrations,
                },
            }));

            this.feedback = {
                type: 'success',
                title: `Camp status updated to ${nextStatus} (Preview)`,
                message: `Updated camp status while retaining all existing registration history.`,
            };
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

export function campRegistration(initial = {}) {
    const camp = initial.camp ?? {};

    return {
        campId: camp.id ?? '',
        campStatus: camp.status ?? 'Draft',
        capacity: Number(camp.capacity) || 0,
        allowWalkIn: Boolean(camp.allowWalkIn),
        registrationDeadline: camp.registrationDeadline ?? '',
        todayIso: initial.todayIso ?? getTodayLocalIso(),
        donors: Array.isArray(initial.donors) ? initial.donors : [],
        registrations: Array.isArray(camp.registrations) ? [...camp.registrations] : [],
        selectedDonorId: '',
        donorSearch: '',
        donorDropdownOpen: false,
        errors: {},
        feedback: null,

        init() {
            window.addEventListener('camp-state-synced', (event) => {
                if (event.detail?.campId !== this.campId) {
                    return;
                }
                if (event.detail.campStatus) {
                    this.campStatus = event.detail.campStatus;
                }
                if (Array.isArray(event.detail.registrations)) {
                    this.registrations = [...event.detail.registrations];
                }
            });
        },

        get activeRegistrationsCount() {
            return countActiveRegistrations(this.registrations);
        },

        get availableSlots() {
            return calculateAvailableSlots(this.capacity, this.activeRegistrationsCount);
        },

        get filteredDonors() {
            const query = this.donorSearch.trim().toLowerCase();
            if (query === '') {
                return this.donors;
            }

            return this.donors.filter((donor) => {
                return (
                    String(donor.id ?? '').toLowerCase().includes(query) ||
                    String(donor.name ?? '').toLowerCase().includes(query) ||
                    String(donor.bloodGroup ?? '').toLowerCase().includes(query)
                );
            });
        },

        get selectedDonor() {
            if (!this.selectedDonorId) {
                return null;
            }
            return this.donors.find((donor) => donor.id === this.selectedDonorId) ?? null;
        },

        get eligibility() {
            return evaluateRegistrationEligibility({
                campStatus: this.campStatus,
                allowWalkIn: this.allowWalkIn,
                registrationDeadline: this.registrationDeadline,
                todayIso: this.todayIso,
                capacity: this.capacity,
                registrations: this.registrations,
                donorId: this.selectedDonorId,
            });
        },

        selectDonor(donorId) {
            this.selectedDonorId = donorId;
            this.donorSearch = '';
            this.donorDropdownOpen = false;
            this.errors = {};
        },

        registerDonor() {
            this.errors = {};

            const donor = this.selectedDonor;
            if (!donor) {
                const statusCheck = evaluateRegistrationEligibility({
                    campStatus: this.campStatus,
                    allowWalkIn: this.allowWalkIn,
                    registrationDeadline: this.registrationDeadline,
                    todayIso: this.todayIso,
                    capacity: this.capacity,
                    registrations: this.registrations,
                    donorId: '',
                });

                this.errors = {
                    donorId: statusCheck.allowed
                        ? 'Select a sample donor to register.'
                        : statusCheck.reason,
                };
                return;
            }

            const check = evaluateRegistrationEligibility({
                campStatus: this.campStatus,
                allowWalkIn: this.allowWalkIn,
                registrationDeadline: this.registrationDeadline,
                todayIso: this.todayIso,
                capacity: this.capacity,
                registrations: this.registrations,
                donorId: donor.id,
            });

            if (!check.allowed) {
                this.errors = { donorId: check.reason };
                this.feedback = {
                    type: 'danger',
                    title: 'Registration blocked',
                    message: check.reason,
                };
                return;
            }

            const newRegId = `REG-${810 + this.registrations.length}`;
            const newEntry = {
                id: newRegId,
                donorId: donor.id,
                donorName: donor.name,
                bloodGroup: donor.bloodGroup,
                registrationDate: this.todayIso,
                attendanceStatus: 'Registered',
            };

            const nextList = [newEntry, ...this.registrations];
            this.registrations = nextList;

            const historyEntry = {
                date: 'Preview Update',
                event: this.campStatus === 'Ongoing' ? 'Walk-in Donor Registered' : 'Donor Registered',
                actor: `${donor.name} (${donor.id})`,
                notes: `Added ${newRegId} (${donor.bloodGroup}) in frontend preview mode.`,
            };

            const feedback = {
                type: 'success',
                title: 'Donor registered (Preview mode)',
                message: `${donor.name} (${donor.id}, ${donor.bloodGroup}) registered as ${newRegId}. Available slots: ${calculateAvailableSlots(this.capacity, countActiveRegistrations(nextList))}.`,
            };

            this.feedback = feedback;
            this.selectedDonorId = '';

            window.dispatchEvent(new CustomEvent('camp-registrations-changed', {
                detail: {
                    campId: this.campId,
                    registrations: nextList,
                    historyEntry,
                    feedback,
                },
            }));
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

