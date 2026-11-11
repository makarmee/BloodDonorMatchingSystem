const VALID_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const VALID_PRIORITIES = ['Low', 'Normal', 'High', 'Critical'];
const TERMINAL_STATUSES = ['Rejected', 'Cancelled'];

export function getTodayLocalIso() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

export function calculateRemainingUnits(requestedUnits, fulfilledUnits) {
    const requested = Math.max(0, Number(requestedUnits) || 0);
    const fulfilled = Math.max(0, Number(fulfilledUnits) || 0);
    return Math.max(0, requested - fulfilled);
}

/**
 * Pure business-rule evaluator for blood request fulfillment & status transitions.
 * Ready to mirror in a backend Laravel service class without UI dependencies.
 */
export function resolveFulfillmentState({
    currentStatus,
    requestedUnits,
    currentFulfilledUnits,
    dispatchUnits = 0,
    targetStatus = 'auto',
}) {
    const requested = Number(requestedUnits);
    const currentFulfilled = Number(currentFulfilledUnits);
    const added = Number(dispatchUnits);

    if (TERMINAL_STATUSES.includes(currentStatus)) {
        return {
            ok: false,
            error: 'Rejected and Cancelled requests cannot receive additional fulfillment.',
        };
    }

    if (!Number.isInteger(added) || added < 0) {
        return {
            ok: false,
            error: 'Fulfilled units must be a whole number of 0 or greater.',
        };
    }

    const nextFulfilled = currentFulfilled + added;

    if (nextFulfilled > requested) {
        return {
            ok: false,
            error: `Fulfilled units (${nextFulfilled}) cannot exceed requested units (${requested}).`,
        };
    }

    if (targetStatus === 'Fulfilled' && nextFulfilled === 0) {
        return {
            ok: false,
            error: 'A request with 0 fulfilled units cannot be marked as Fulfilled.',
        };
    }

    let resolvedStatus = currentStatus;

    if (nextFulfilled > 0 && nextFulfilled < requested) {
        if (targetStatus === 'Fulfilled') {
            return {
                ok: false,
                error: 'Cannot mark status as Fulfilled while remaining units are still pending. Status resolves to Partially Fulfilled.',
            };
        }
        resolvedStatus = 'Partially Fulfilled';
    } else if (nextFulfilled === requested && requested > 0) {
        resolvedStatus = 'Fulfilled';
    } else if (targetStatus !== 'auto') {
        resolvedStatus = targetStatus;
    }

    return {
        ok: true,
        fulfilledUnits: nextFulfilled,
        remainingUnits: calculateRemainingUnits(requested, nextFulfilled),
        status: resolvedStatus,
    };
}

export function requestsDirectory(initial = {}) {
    return {
        requests: Array.isArray(initial.requests) ? initial.requests : [],
        search: '',
        bloodGroupFilter: 'all',
        priorityFilter: 'all',
        statusFilter: 'all',
        dateFrom: '',
        dateTo: '',
        currentPage: 1,
        perPage: 5,

        init() {
            const resetPage = () => {
                this.currentPage = 1;
            };

            this.$watch('search', resetPage);
            this.$watch('bloodGroupFilter', resetPage);
            this.$watch('priorityFilter', resetPage);
            this.$watch('statusFilter', resetPage);
            this.$watch('dateFrom', resetPage);
            this.$watch('dateTo', resetPage);
            this.$watch('perPage', resetPage);
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.requests.reduce((matches, item, index) => {
                const id = String(item.id ?? '').toLowerCase();
                const hospitalName = String(item.hospitalName ?? '').toLowerCase();
                const requestDate = String(item.requestDate ?? '');

                const matchesSearch =
                    query === '' ||
                    id.includes(query) ||
                    hospitalName.includes(query);

                const matchesBloodGroup =
                    this.bloodGroupFilter === 'all' ||
                    item.bloodGroup === this.bloodGroupFilter;

                const matchesPriority =
                    this.priorityFilter === 'all' ||
                    item.priority === this.priorityFilter;

                const matchesStatus =
                    this.statusFilter === 'all' ||
                    item.status === this.statusFilter;

                const matchesDateFrom =
                    this.dateFrom === '' ||
                    (requestDate !== '' && requestDate >= this.dateFrom);

                const matchesDateTo =
                    this.dateTo === '' ||
                    (requestDate !== '' && requestDate <= this.dateTo);

                if (
                    matchesSearch &&
                    matchesBloodGroup &&
                    matchesPriority &&
                    matchesStatus &&
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
                this.bloodGroupFilter !== 'all' ||
                this.priorityFilter !== 'all' ||
                this.statusFilter !== 'all' ||
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
            this.bloodGroupFilter = 'all';
            this.priorityFilter = 'all';
            this.statusFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
        },
    };
}

export function requestForm(initial = {}) {
    const initialForm = {
        hospitalName: initial.form?.hospitalName ?? '',
        contactPerson: initial.form?.contactPerson ?? '',
        contactNumber: initial.form?.contactNumber ?? '',
        hospitalAddress: initial.form?.hospitalAddress ?? '',
        email: initial.form?.email ?? '',
        bloodGroup: initial.form?.bloodGroup ?? '',
        requestedUnits: initial.form?.requestedUnits ?? '',
        requiredDate: initial.form?.requiredDate ?? '',
        priority: initial.form?.priority ?? '',
        requestReason: initial.form?.requestReason ?? '',
        additionalNotes: initial.form?.additionalNotes ?? '',
    };

    return {
        mode: initial.mode === 'edit' ? 'edit' : 'create',
        requestId: initial.requestId ?? null,
        existingFulfilledUnits: Number(initial.existingFulfilledUnits) || 0,
        todayIso: initial.todayIso ?? getTodayLocalIso(),
        snapshot: { ...initialForm },
        form: { ...initialForm },
        errors: {},
        feedback: null,

        validateField(field) {
            const value = String(this.form[field] ?? '').trim();
            let error = '';

            if (field === 'hospitalName') {
                if (value === '') {
                    error = 'Hospital name is required.';
                } else if (value.length < 3) {
                    error = 'Hospital name must be at least 3 characters.';
                }
            } else if (field === 'contactPerson') {
                if (value === '') {
                    error = 'Contact person is required.';
                } else if (value.length < 2) {
                    error = 'Contact person must be at least 2 characters.';
                }
            } else if (field === 'contactNumber') {
                const digitsOnly = value.replace(/\D/g, '');
                if (value === '') {
                    error = 'Contact number is required.';
                } else if (!/^\+?[0-9\s\-().]{7,20}$/.test(value) || digitsOnly.length < 7) {
                    error = 'Enter a valid contact number with at least 7 digits.';
                }
            } else if (field === 'hospitalAddress') {
                if (value === '') {
                    error = 'Hospital address is required.';
                } else if (value.length < 5) {
                    error = 'Enter a complete facility address.';
                }
            } else if (field === 'email') {
                if (value === '') {
                    error = 'Email address is required.';
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    error = 'Enter a valid hospital email address.';
                }
            } else if (field === 'bloodGroup') {
                if (!VALID_BLOOD_GROUPS.includes(value)) {
                    error = 'Select one of the 8 standard blood groups.';
                }
            } else if (field === 'requestedUnits') {
                const numeric = Number(value);
                if (value === '' || !Number.isFinite(numeric)) {
                    error = 'Number of units required is required.';
                } else if (!Number.isInteger(numeric) || numeric < 1) {
                    error = 'Units required must be a positive whole number.';
                } else if (this.mode === 'edit' && numeric < this.existingFulfilledUnits) {
                    error = `Requested units cannot be less than already fulfilled units (${this.existingFulfilledUnits}).`;
                }
            } else if (field === 'requiredDate') {
                if (value === '') {
                    error = 'Required date is required.';
                } else if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    error = 'Enter a valid required date.';
                } else if (value < this.todayIso && (this.mode === 'create' || value !== this.snapshot.requiredDate)) {
                    error = 'Required date must not be in the past.';
                }
            } else if (field === 'priority') {
                if (!VALID_PRIORITIES.includes(value)) {
                    error = 'Select Low, Normal, High, or Critical priority.';
                }
            } else if (field === 'requestReason') {
                if (value === '') {
                    error = 'Request reason is required.';
                } else if (value.length < 5) {
                    error = 'Provide a brief clinical reason for this blood request.';
                }
            } else if (field === 'additionalNotes') {
                if (value.length > 1000) {
                    error = 'Additional notes cannot exceed 1000 characters.';
                }
            }

            if (error) {
                this.errors = { ...this.errors, [field]: error };
                return false;
            }

            const nextErrors = { ...this.errors };
            delete nextErrors[field];
            this.errors = nextErrors;
            return true;
        },

        validateAll() {
            const fields = [
                'hospitalName',
                'contactPerson',
                'contactNumber',
                'hospitalAddress',
                'email',
                'bloodGroup',
                'requestedUnits',
                'requiredDate',
                'priority',
                'requestReason',
                'additionalNotes',
            ];

            let allValid = true;
            for (const field of fields) {
                if (!this.validateField(field)) {
                    allValid = false;
                }
            }

            return allValid;
        },

        submitForm() {
            if (!this.validateAll()) {
                this.feedback = {
                    type: 'danger',
                    title: 'Validation errors found',
                    message: 'Please correct the highlighted hospital or blood request fields before continuing.',
                };
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            if (this.mode === 'edit') {
                this.feedback = {
                    type: 'success',
                    title: 'Blood request updated (Preview mode)',
                    message: `Changes for ${this.requestId ?? 'request'} (${this.form.hospitalName}, ${this.form.requestedUnits} units of ${this.form.bloodGroup}, ${this.form.priority} priority) passed validation without altering database or inventory records.`,
                };
            } else {
                this.feedback = {
                    type: 'success',
                    title: 'Blood request validated (Preview mode)',
                    message: `Order for ${this.form.hospitalName} (${this.form.requestedUnits} units of ${this.form.bloodGroup}, required ${this.form.requiredDate}) is ready. Backend persistence will store and triage new orders in the next phase.`,
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

export function requestFulfillment(initial = {}) {
    const requestData = initial.request ?? {};
    const inventoryStock = initial.inventoryStock ?? {};
    const requestedUnits = Number(requestData.requestedUnits) || 0;
    const fulfilledUnits = Number(requestData.fulfilledUnits) || 0;

    return {
        requestId: requestData.id ?? '',
        hospitalName: requestData.hospitalName ?? '',
        requestBloodGroup: requestData.bloodGroup ?? 'O+',
        requestedUnits,
        fulfilledUnits,
        status: requestData.status ?? 'Pending',
        history: Array.isArray(requestData.history) ? [...requestData.history] : [],
        inventoryStock,
        todayIso: initial.todayIso ?? getTodayLocalIso(),

        dispatch: {
            bloodGroup: requestData.bloodGroup ?? 'O+',
            units: Math.min(
                calculateRemainingUnits(requestedUnits, fulfilledUnits),
                Math.max(0, Number(inventoryStock[requestData.bloodGroup] ?? 0))
            ) || Math.min(1, calculateRemainingUnits(requestedUnits, fulfilledUnits)),
            dispatchDate: initial.todayIso ?? getTodayLocalIso(),
            targetStatus: 'auto',
            notes: '',
        },

        rejectionReason: '',
        errors: {},
        feedback: null,

        get remainingUnits() {
            return calculateRemainingUnits(this.requestedUnits, this.fulfilledUnits);
        },

        get progressPercent() {
            if (this.requestedUnits <= 0) {
                return 0;
            }
            return Math.min(100, Math.round((this.fulfilledUnits / this.requestedUnits) * 100));
        },

        get isTerminalBlocked() {
            return TERMINAL_STATUSES.includes(this.status);
        },

        get canApprove() {
            return this.status === 'Pending';
        },

        get canReject() {
            return !TERMINAL_STATUSES.includes(this.status) && this.status !== 'Fulfilled';
        },

        get canRecordFulfillment() {
            return !this.isTerminalBlocked && this.status !== 'Fulfilled' && this.remainingUnits > 0;
        },

        get selectedGroupAvailableStock() {
            return Math.max(0, Number(this.inventoryStock[this.dispatch.bloodGroup] ?? 0));
        },

        get isInventoryInsufficient() {
            const unitsRequestedNow = Number(this.dispatch.units) || 0;
            if (this.isTerminalBlocked || this.status === 'Fulfilled') {
                return false;
            }

            return (
                this.selectedGroupAvailableStock < this.remainingUnits ||
                unitsRequestedNow > this.selectedGroupAvailableStock
            );
        },

        get previewResult() {
            return resolveFulfillmentState({
                currentStatus: this.status,
                requestedUnits: this.requestedUnits,
                currentFulfilledUnits: this.fulfilledUnits,
                dispatchUnits: this.dispatch.units,
                targetStatus: this.dispatch.targetStatus,
            });
        },

        statusBadgeClasses(status) {
            const map = {
                Pending: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70',
                Approved: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70',
                'Partially Fulfilled': 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/70 dark:text-crimson-300 dark:ring-crimson-800/70',
                Fulfilled: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70',
                Rejected: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70',
                Cancelled: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
            };
            return map[status] ?? map.Pending;
        },

        approveRequest() {
            this.errors = {};

            if (this.isTerminalBlocked) {
                this.feedback = {
                    type: 'danger',
                    title: 'Approval blocked',
                    message: 'Rejected and Cancelled requests cannot be approved or fulfilled.',
                };
                return;
            }

            if (this.status !== 'Pending') {
                this.feedback = {
                    type: 'danger',
                    title: 'Already reviewed',
                    message: `Request ${this.requestId} is currently "${this.status}" and does not require initial approval.`,
                };
                return;
            }

            this.status = 'Approved';
            this.prependHistory({
                event: 'Request Approved (Preview)',
                status: 'Approved',
                units: 0,
                actor: 'Blood Bank Staff (Preview)',
                notes: `Approved ${this.requestedUnits} units of ${this.requestBloodGroup} for dispatch triage.`,
            });

            this.feedback = {
                type: 'success',
                title: 'Request approved (Preview mode)',
                message: `${this.requestId} status updated to Approved. You may now record unit dispatch without altering live Inventory records.`,
            };
        },

        submitRejection() {
            const reason = this.rejectionReason.trim();
            if (reason.length < 5) {
                this.errors = {
                    ...this.errors,
                    rejectionReason: 'Provide a clear clinical or operational reason (at least 5 characters).',
                };
                return;
            }

            if (this.isTerminalBlocked || this.status === 'Fulfilled') {
                this.feedback = {
                    type: 'danger',
                    title: 'Cannot reject request',
                    message: `Requests marked "${this.status}" cannot be rejected.`,
                };
                return;
            }

            const nextErrors = { ...this.errors };
            delete nextErrors.rejectionReason;
            this.errors = nextErrors;

            this.status = 'Rejected';
            this.prependHistory({
                event: 'Request Rejected (Preview)',
                status: 'Rejected',
                units: 0,
                actor: 'Blood Bank Staff (Preview)',
                notes: reason,
            });

            this.rejectionReason = '';
            this.$dispatch('close-modal', 'reject-request-modal');
            this.feedback = {
                type: 'danger',
                title: 'Request rejected (Preview mode)',
                message: `${this.requestId} marked as Rejected. Additional fulfillment is now locked.`,
            };
        },

        recordFulfillment() {
            this.errors = {};

            if (this.isTerminalBlocked) {
                this.feedback = {
                    type: 'danger',
                    title: 'Fulfillment blocked',
                    message: 'Rejected and Cancelled requests cannot receive additional fulfillment.',
                };
                return;
            }

            const unitsToDispatch = Number(this.dispatch.units);
            if (String(this.dispatch.units).trim() === '' || !Number.isInteger(unitsToDispatch) || unitsToDispatch < 1) {
                this.errors = {
                    ...this.errors,
                    dispatchUnits: 'Enter a positive whole number of units to dispatch.',
                };
                return;
            }

            if (!VALID_BLOOD_GROUPS.includes(this.dispatch.bloodGroup)) {
                this.errors = {
                    ...this.errors,
                    dispatchBloodGroup: 'Select a valid blood group from sample inventory.',
                };
                return;
            }

            if (!/^\d{4}-\d{2}-\d{2}$/.test(String(this.dispatch.dispatchDate ?? '').trim())) {
                this.errors = {
                    ...this.errors,
                    dispatchDate: 'Select a valid dispatch date.',
                };
                return;
            }

            const evaluated = resolveFulfillmentState({
                currentStatus: this.status,
                requestedUnits: this.requestedUnits,
                currentFulfilledUnits: this.fulfilledUnits,
                dispatchUnits: unitsToDispatch,
                targetStatus: this.dispatch.targetStatus,
            });

            if (!evaluated.ok) {
                this.errors = {
                    ...this.errors,
                    dispatchUnits: evaluated.error,
                };
                this.feedback = {
                    type: 'danger',
                    title: 'Business rule violation',
                    message: evaluated.error,
                };
                return;
            }

            this.fulfilledUnits = evaluated.fulfilledUnits;
            this.status = evaluated.status;

            const dispatchNotesText = this.dispatch.notes.trim() ||
                `Dispatched ${unitsToDispatch} unit(s) of ${this.dispatch.bloodGroup} on ${this.dispatch.dispatchDate} (preview only; inventory unmodified).`;

            this.prependHistory({
                event: evaluated.status === 'Fulfilled'
                    ? 'Full Fulfillment Recorded (Preview)'
                    : 'Partial Dispatch Recorded (Preview)',
                status: evaluated.status,
                units: unitsToDispatch,
                actor: 'Blood Bank Staff (Preview)',
                notes: `${dispatchNotesText} [Blood Group: ${this.dispatch.bloodGroup} · Date: ${this.dispatch.dispatchDate}]`,
            });

            this.dispatch.units = Math.min(1, evaluated.remainingUnits);
            this.dispatch.notes = '';
            this.dispatch.targetStatus = 'auto';

            const stockNote = this.selectedGroupAvailableStock < unitsToDispatch
                ? ' Sample inventory appeared insufficient; Inventory module records remain unchanged.'
                : ' Inventory balances remain unchanged in preview mode.';

            this.feedback = {
                type: 'success',
                title: `Fulfillment updated → ${this.status} (Preview mode)`,
                message: `Recorded ${unitsToDispatch} unit(s). Fulfilled: ${this.fulfilledUnits}/${this.requestedUnits}, Remaining: ${this.remainingUnits}.${stockNote}`,
            };
        },

        prependHistory(entry) {
            const now = new Date();
            const formattedNow = now.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
            });
            this.history.unshift({
                date: `${formattedNow} • Preview`,
                event: entry.event,
                status: entry.status,
                units: entry.units,
                actor: entry.actor,
                notes: entry.notes,
            });
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

