const VALID_TEST_TYPES = [
    'HIV',
    'Hepatitis B',
    'Hepatitis C',
    'Syphilis',
    'Blood Group Compatibility',
];

const VALID_SCREENING_RESULTS = ['Pending', 'Passed', 'Failed'];

export function screeningDirectory(initial = {}) {
    return {
        samples: Array.isArray(initial.samples) ? initial.samples : [],
        search: '',
        bloodGroupFilter: 'all',
        resultFilter: 'all',
        testTypeFilter: 'all',
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
            this.$watch('resultFilter', resetPage);
            this.$watch('testTypeFilter', resetPage);
            this.$watch('dateFrom', resetPage);
            this.$watch('dateTo', resetPage);
            this.$watch('perPage', resetPage);
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.samples.reduce((matches, sample, index) => {
                const id = String(sample.id ?? '').toLowerCase();
                const donationId = String(sample.donationId ?? '').toLowerCase();
                const donorName = String(sample.donorName ?? '').toLowerCase();
                const donorId = String(sample.donorId ?? '').toLowerCase();
                const effectiveDate = String(sample.testDate || sample.collectionDate || '');

                const matchesSearch =
                    query === '' ||
                    id.includes(query) ||
                    donationId.includes(query) ||
                    donorName.includes(query) ||
                    donorId.includes(query);

                const matchesBloodGroup =
                    this.bloodGroupFilter === 'all' ||
                    sample.bloodGroup === this.bloodGroupFilter;

                const matchesResult =
                    this.resultFilter === 'all' ||
                    sample.screeningResult === this.resultFilter;

                const matchesTestType =
                    this.testTypeFilter === 'all' ||
                    sample.testType === this.testTypeFilter;

                const matchesDateFrom =
                    this.dateFrom === '' ||
                    (effectiveDate !== '' && effectiveDate >= this.dateFrom);

                const matchesDateTo =
                    this.dateTo === '' ||
                    (effectiveDate !== '' && effectiveDate <= this.dateTo);

                if (
                    matchesSearch &&
                    matchesBloodGroup &&
                    matchesResult &&
                    matchesTestType &&
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
                this.resultFilter !== 'all' ||
                this.testTypeFilter !== 'all' ||
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
            this.resultFilter = 'all';
            this.testTypeFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
        },
    };
}

export function screeningForm(initial = {}) {
    const donationsList = Array.isArray(initial.donations) ? initial.donations : [];

    const initialForm = {
        donationId: initial.form?.donationId ?? '',
        testType: initial.form?.testType ?? '',
        testDate: initial.form?.testDate ?? '',
        screeningResult: initial.form?.screeningResult ?? '',
        labStaff: initial.form?.labStaff ?? '',
        testNotes: initial.form?.testNotes ?? '',
    };

    return {
        donations: donationsList,
        donationSearch: '',
        donationDropdownOpen: false,
        snapshot: { ...initialForm },
        form: { ...initialForm },
        errors: {},
        feedback: null,

        get filteredDonations() {
            const query = this.donationSearch.trim().toLowerCase();
            if (query === '') {
                return this.donations;
            }

            return this.donations.filter((donation) => {
                const id = String(donation.id ?? '').toLowerCase();
                const donorName = String(donation.donorName ?? '').toLowerCase();
                const donorId = String(donation.donorId ?? '').toLowerCase();
                const group = String(donation.bloodGroup ?? '').toLowerCase();
                return id.includes(query) || donorName.includes(query) || donorId.includes(query) || group.includes(query);
            });
        },

        get selectedDonation() {
            const targetId = String(this.form.donationId ?? '').trim();
            if (targetId === '') {
                return null;
            }

            return this.donations.find((donation) => donation.id === targetId) ?? null;
        },

        selectDonation(donationId) {
            this.form.donationId = donationId;
            this.donationSearch = '';
            this.donationDropdownOpen = false;
            this.validateField('donationId');
        },

        validateField(field) {
            const value = String(this.form[field] ?? '').trim();
            let error = '';

            if (field === 'donationId') {
                if (value === '' || !this.donations.some((donation) => donation.id === value)) {
                    error = 'Select an existing donation record to link this test.';
                }
            } else if (field === 'testType') {
                if (!VALID_TEST_TYPES.includes(value)) {
                    error = 'Select one of the supported screening test types.';
                }
            } else if (field === 'testDate') {
                if (value === '') {
                    error = 'Test date is required.';
                } else if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    error = 'Enter a valid test date.';
                } else {
                    const parsed = new Date(`${value}T00:00:00`);
                    if (Number.isNaN(parsed.getTime())) {
                        error = 'Enter a valid test date.';
                    }
                }
            } else if (field === 'screeningResult') {
                if (!VALID_SCREENING_RESULTS.includes(value)) {
                    error = 'Explicitly select Pending, Passed, or Failed screening result.';
                }
            } else if (field === 'labStaff') {
                if (value === '') {
                    error = 'Laboratory staff member is required.';
                } else if (value.length < 2) {
                    error = 'Enter the responsible laboratory staff name.';
                }
            } else if (field === 'testNotes') {
                if (value.length > 1000) {
                    error = 'Test notes cannot exceed 1000 characters.';
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
                'donationId',
                'testType',
                'testDate',
                'screeningResult',
                'labStaff',
                'testNotes',
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
                    message: 'Please review the highlighted screening fields before previewing submission.',
                };
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const linked = this.selectedDonation;
            const summaryTarget = linked
                ? `${linked.id} (${linked.donorName}, ${linked.bloodGroup})`
                : this.form.donationId;

            this.feedback = {
                type: 'success',
                title: 'Lab screening validated (Preview mode only)',
                message: `Recorded ${this.form.testType} assay for ${summaryTarget} with explicit screening result "${this.form.screeningResult}". Note: Sample preview data does not represent real clinical clearance.`,
            };

            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        resetForm() {
            this.form = { ...this.snapshot };
            this.donationSearch = '';
            this.donationDropdownOpen = false;
            this.errors = {};
            this.feedback = null;
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

