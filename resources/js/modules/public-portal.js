export function publicBloodStock(initial = {}) {
    return {
        groups: Array.isArray(initial.groups) ? initial.groups : [],
        groupFilter: 'all',
        statusFilter: 'all',

        get filteredIndices() {
            const indices = [];

            this.groups.forEach((item, index) => {
                if (this.groupFilter !== 'all' && item.group !== this.groupFilter) {
                    return;
                }

                if (this.statusFilter !== 'all' && item.status !== this.statusFilter) {
                    return;
                }

                indices.push(index);
            });

            return indices;
        },

        get filteredCount() {
            return this.filteredIndices.length;
        },

        get hasActiveFilters() {
            return this.groupFilter !== 'all' || this.statusFilter !== 'all';
        },

        isGroupVisible(index) {
            return this.filteredIndices.includes(index);
        },

        resetFilters() {
            this.groupFilter = 'all';
            this.statusFilter = 'all';
        },
    };
}

export function publicCampsDirectory(initial = {}) {
    return {
        camps: Array.isArray(initial.camps) ? initial.camps : [],
        search: '',
        statusFilter: 'all',
        cityFilter: 'all',
        openOnly: false,
        dateFrom: '',
        dateTo: '',
        currentPage: 1,
        perPage: Number(initial.perPage) || 4,

        init() {
            const resetPage = () => {
                this.currentPage = 1;
            };

            if (typeof this.$watch === 'function') {
                this.$watch('search', resetPage);
                this.$watch('statusFilter', resetPage);
                this.$watch('cityFilter', resetPage);
                this.$watch('openOnly', resetPage);
                this.$watch('dateFrom', resetPage);
                this.$watch('dateTo', resetPage);
                this.$watch('perPage', resetPage);
            }
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();
            const indices = [];

            this.camps.forEach((camp, index) => {
                if (query !== '') {
                    const haystack = [
                        camp.id,
                        camp.name,
                        camp.city,
                        camp.venueName,
                        camp.fullAddress,
                    ]
                        .join(' ')
                        .toLowerCase();

                    if (!haystack.includes(query)) {
                        return;
                    }
                }

                if (this.statusFilter !== 'all' && camp.status !== this.statusFilter) {
                    return;
                }

                if (this.cityFilter !== 'all' && camp.city !== this.cityFilter) {
                    return;
                }

                if (this.openOnly && !camp.isBookingOpen) {
                    return;
                }

                if (this.dateFrom !== '' && camp.endDate < this.dateFrom) {
                    return;
                }

                if (this.dateTo !== '' && camp.startDate > this.dateTo) {
                    return;
                }

                indices.push(index);
            });

            return indices;
        },

        get totalFiltered() {
            return this.filteredIndices.length;
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.totalFiltered / this.perPage));
        },

        get safePage() {
            return Math.min(Math.max(1, this.currentPage), this.totalPages);
        },

        get paginatedIndices() {
            const start = (this.safePage - 1) * this.perPage;
            return this.filteredIndices.slice(start, start + this.perPage);
        },

        get showingFrom() {
            if (this.totalFiltered === 0) {
                return 0;
            }

            return (this.safePage - 1) * this.perPage + 1;
        },

        get showingTo() {
            return Math.min(this.totalFiltered, this.safePage * this.perPage);
        },

        get hasActiveFilters() {
            return (
                this.search.trim() !== '' ||
                this.statusFilter !== 'all' ||
                this.cityFilter !== 'all' ||
                this.openOnly ||
                this.dateFrom !== '' ||
                this.dateTo !== ''
            );
        },

        isCampVisible(index) {
            return this.paginatedIndices.includes(index);
        },

        prevPage() {
            if (this.safePage > 1) {
                this.currentPage = this.safePage - 1;
            }
        },

        nextPage() {
            if (this.safePage < this.totalPages) {
                this.currentPage = this.safePage + 1;
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
            this.cityFilter = 'all';
            this.openOnly = false;
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
        },
    };
}

