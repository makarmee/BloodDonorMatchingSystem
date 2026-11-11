export function resolveStockStatus(available, minLevel) {
    const criticalThreshold = Math.floor(minLevel * 0.6);

    if (available <= criticalThreshold) {
        return 'critical';
    }

    if (available <= minLevel) {
        return 'low';
    }

    return 'normal';
}

export function statusLabel(status) {
    if (status === 'critical') {
        return 'Critical';
    }

    if (status === 'low') {
        return 'Low Stock';
    }

    return 'Normal';
}

export function inventoryManager(initial) {
    return {
        groups: initial.groups || [],
        history: initial.history || [],
        actor: initial.actor || 'Blood Bank Staff',

        search: '',
        statusFilter: 'all',
        groupFilter: 'all',
        perPage: 4,
        currentPage: 1,

        historyFilter: 'all',

        feedbackMessage: '',
        feedbackTimer: null,

        form: {
            bloodGroup: '',
            operation: 'Added',
            units: '',
            description: '',
        },
        errors: {},

        init() {
            this.$watch('search', () => {
                this.currentPage = 1;
            });
            this.$watch('statusFilter', () => {
                this.currentPage = 1;
            });
            this.$watch('groupFilter', () => {
                this.currentPage = 1;
            });
            this.$watch('perPage', () => {
                this.currentPage = 1;
            });
        },

        get totalUnits() {
            return this.groups.reduce((sum, item) => sum + Number(item.available || 0), 0);
        },

        get lowStockCount() {
            return this.groups.filter((item) => item.status === 'low').length;
        },

        get criticalStockCount() {
            return this.groups.filter((item) => item.status === 'critical').length;
        },

        get hasActiveFilters() {
            return this.search.trim() !== '' || this.statusFilter !== 'all' || this.groupFilter !== 'all';
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.groups
                .map((item, index) => ({ item, index }))
                .filter(({ item }) => {
                    if (this.statusFilter !== 'all' && item.status !== this.statusFilter) {
                        return false;
                    }

                    if (this.groupFilter !== 'all' && item.group !== this.groupFilter) {
                        return false;
                    }

                    if (query !== '') {
                        const label = statusLabel(item.status).toLowerCase();
                        return item.group.toLowerCase().includes(query) || label.includes(query);
                    }

                    return true;
                })
                .map(({ index }) => index);
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredIndices.length / this.perPage));
        },

        get paginatedIndices() {
            const safePage = Math.min(this.currentPage, this.totalPages);
            const start = (safePage - 1) * this.perPage;
            return this.filteredIndices.slice(start, start + this.perPage);
        },

        get pageStart() {
            if (this.filteredIndices.length === 0) {
                return 0;
            }
            const safePage = Math.min(this.currentPage, this.totalPages);
            return (safePage - 1) * this.perPage + 1;
        },

        get pageEnd() {
            if (this.filteredIndices.length === 0) {
                return 0;
            }
            const safePage = Math.min(this.currentPage, this.totalPages);
            return Math.min(safePage * this.perPage, this.filteredIndices.length);
        },

        isRowVisible(index) {
            return this.paginatedIndices.includes(index);
        },

        prevPage() {
            if (this.currentPage > 1) {
                this.currentPage -= 1;
            }
        },

        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.currentPage += 1;
            }
        },

        goToPage(page) {
            if (page >= 1 && page <= this.totalPages) {
                this.currentPage = page;
            }
        },

        resetFilters() {
            this.search = '';
            this.statusFilter = 'all';
            this.groupFilter = 'all';
            this.currentPage = 1;
        },

        get filteredHistory() {
            if (this.historyFilter === 'all') {
                return this.history;
            }

            return this.history.filter(
                (entry) => entry.operation.toLowerCase() === this.historyFilter.toLowerCase(),
            );
        },

        get selectedGroupItem() {
            return this.groups.find((item) => item.group === this.form.bloodGroup) || null;
        },

        stockPercent(item) {
            if (!item || !item.capacity) {
                return 0;
            }
            return Math.min(100, Math.round((item.available / item.capacity) * 100));
        },

        statusText(status) {
            return statusLabel(status);
        },

        statusBadgeClasses(status) {
            if (status === 'critical') {
                return 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70';
            }
            if (status === 'low') {
                return 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70';
            }
            return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70';
        },

        statusDotClasses(status) {
            if (status === 'critical') {
                return 'bg-rose-500';
            }
            if (status === 'low') {
                return 'bg-amber-500';
            }
            return 'bg-emerald-500';
        },

        barColorClasses(status) {
            if (status === 'critical') {
                return 'bg-rose-600 dark:bg-rose-500';
            }
            if (status === 'low') {
                return 'bg-amber-500 dark:bg-amber-400';
            }
            return 'bg-emerald-600 dark:bg-emerald-500';
        },

        groupPillClasses(group) {
            const normalized = String(group || '').toUpperCase();
            if (normalized.startsWith('O')) {
                return 'bg-crimson-50 text-crimson-700 ring-crimson-200 dark:bg-crimson-950/70 dark:text-crimson-300 dark:ring-crimson-800/70';
            }
            if (normalized.startsWith('AB')) {
                return 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950/60 dark:text-violet-300 dark:ring-violet-800/70';
            }
            if (normalized.startsWith('A')) {
                return 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70';
            }
            if (normalized.startsWith('B')) {
                return 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:ring-amber-800/70';
            }
            return 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700';
        },

        operationBadgeClasses(operation) {
            if (operation === 'Added') {
                return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:ring-emerald-800/70';
            }
            if (operation === 'Removed') {
                return 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:ring-rose-800/70';
            }
            return 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:ring-sky-800/70';
        },

        openAdjustModal(group = '') {
            this.errors = {};
            this.form = {
                bloodGroup: group || this.groups[0]?.group || 'A+',
                operation: 'Added',
                units: '',
                description: '',
            };
            this.$dispatch('open-modal', 'adjust-inventory');
        },

        closeAdjustModal() {
            this.errors = {};
            this.$dispatch('close-modal', 'adjust-inventory');
        },

        validateForm() {
            const errors = {};
            const target = this.selectedGroupItem;
            const parsedUnits = Number(this.form.units);

            if (!this.form.bloodGroup || !target) {
                errors.bloodGroup = 'Please select a valid blood group.';
            }

            if (!['Added', 'Removed', 'Reserved'].includes(this.form.operation)) {
                errors.operation = 'Please select an operation type.';
            }

            if (!Number.isInteger(parsedUnits) || parsedUnits < 1) {
                errors.units = 'Enter a whole number of units (minimum 1).';
            } else if (
                target &&
                (this.form.operation === 'Removed' || this.form.operation === 'Reserved') &&
                parsedUnits > target.available
            ) {
                errors.units = `Only ${target.available} units of ${target.group} are currently available.`;
            }

            if (!this.form.description || this.form.description.trim().length < 3) {
                errors.description = 'Please enter a brief description (at least 3 characters).';
            }

            this.errors = errors;
            return Object.keys(errors).length === 0;
        },

        submitAdjustment() {
            if (!this.validateForm()) {
                return;
            }

            const target = this.selectedGroupItem;
            const units = Number(this.form.units);
            const operation = this.form.operation;
            const description = this.form.description.trim();

            if (operation === 'Added') {
                target.available += units;
            } else if (operation === 'Removed') {
                target.available -= units;
            } else if (operation === 'Reserved') {
                target.available -= units;
                target.reserved += units;
            }

            target.status = resolveStockStatus(target.available, target.minLevel);
            target.lastUpdated = 'Just now';

            const now = new Date();
            const formattedDate = now.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
            });
            const formattedTime = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            });

            this.history.unshift({
                id: Date.now(),
                group: target.group,
                operation,
                units,
                date: `${formattedDate} • ${formattedTime}`,
                user: this.actor,
                description,
            });

            this.feedbackMessage = `${operation} ${units} ${units === 1 ? 'unit' : 'units'} for ${target.group} (${description}).`;
            if (this.feedbackTimer) {
                clearTimeout(this.feedbackTimer);
            }
            this.feedbackTimer = setTimeout(() => {
                this.feedbackMessage = '';
            }, 6000);

            this.closeAdjustModal();
        },
    };
}

