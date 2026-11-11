const VALID_DONATION_TYPES = ['Whole Blood', 'Plasma', 'Platelets'];
const VALID_SCREENING_STATUSES = ['Pending', 'Passed', 'Failed'];
const VALID_COLLECTION_STATUSES = ['Scheduled', 'Completed', 'Cancelled'];

export function donationsDirectory(initial = {}) {
    return {
        donations: Array.isArray(initial.donations) ? initial.donations : [],
        search: '',
        bloodGroupFilter: 'all',
        screeningFilter: 'all',
        collectionFilter: 'all',
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
            this.$watch('screeningFilter', resetPage);
            this.$watch('collectionFilter', resetPage);
            this.$watch('dateFrom', resetPage);
            this.$watch('dateTo', resetPage);
            this.$watch('perPage', resetPage);
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.donations.reduce((matches, donation, index) => {
                const id = String(donation.id ?? '').toLowerCase();
                const donorName = String(donation.donorName ?? '').toLowerCase();
                const donorId = String(donation.donorId ?? '').toLowerCase();
                const donationDate = String(donation.donationDate ?? '');

                const matchesSearch =
                    query === '' ||
                    id.includes(query) ||
                    donorName.includes(query) ||
                    donorId.includes(query);

                const matchesBloodGroup =
                    this.bloodGroupFilter === 'all' ||
                    donation.bloodGroup === this.bloodGroupFilter;

                const matchesScreening =
                    this.screeningFilter === 'all' ||
                    donation.screeningStatus === this.screeningFilter;

                const matchesCollection =
                    this.collectionFilter === 'all' ||
                    donation.collectionStatus === this.collectionFilter;

                const matchesDateFrom =
                    this.dateFrom === '' ||
                    (donationDate !== '' && donationDate >= this.dateFrom);

                const matchesDateTo =
                    this.dateTo === '' ||
                    (donationDate !== '' && donationDate <= this.dateTo);

                if (
                    matchesSearch &&
                    matchesBloodGroup &&
                    matchesScreening &&
                    matchesCollection &&
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
                this.screeningFilter !== 'all' ||
                this.collectionFilter !== 'all' ||
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
            this.screeningFilter = 'all';
            this.collectionFilter = 'all';
            this.dateFrom = '';
            this.dateTo = '';
            this.currentPage = 1;
        },
    };
}

export function donationForm(initial = {}) {
    const donorsList = Array.isArray(initial.donors) ? initial.donors : [];

    const initialForm = {
        donorId: initial.form?.donorId ?? '',
        donationDate: initial.form?.donationDate ?? '',
        donationType: initial.form?.donationType ?? '',
        quantityMl: initial.form?.quantityMl ?? '',
        collectionLocation: initial.form?.collectionLocation ?? '',
        staffMember: initial.form?.staffMember ?? '',
        screeningStatus: initial.form?.screeningStatus ?? '',
        screeningNotes: initial.form?.screeningNotes ?? '',
        collectionStatus: initial.form?.collectionStatus ?? '',
        collectionNotes: initial.form?.collectionNotes ?? '',
    };

    return {
        mode: initial.mode === 'edit' ? 'edit' : 'create',
        donationId: initial.donationId ?? null,
        donors: donorsList,
        donorSearch: '',
        donorDropdownOpen: false,
        snapshot: { ...initialForm },
        form: { ...initialForm },
        errors: {},
        feedback: null,

        get filteredDonors() {
            const query = this.donorSearch.trim().toLowerCase();
            if (query === '') {
                return this.donors;
            }

            return this.donors.filter((donor) => {
                const id = String(donor.id ?? '').toLowerCase();
                const name = String(donor.name ?? '').toLowerCase();
                const group = String(donor.bloodGroup ?? '').toLowerCase();
                return id.includes(query) || name.includes(query) || group.includes(query);
            });
        },

        get selectedDonor() {
            const targetId = String(this.form.donorId ?? '').trim();
            if (targetId === '') {
                return null;
            }

            return this.donors.find((donor) => donor.id === targetId) ?? null;
        },

        selectDonor(donorId) {
            this.form.donorId = donorId;
            this.donorSearch = '';
            this.donorDropdownOpen = false;
            this.validateField('donorId');
        },

        validateField(field) {
            const raw = this.form[field];
            const value = String(raw ?? '').trim();
            let error = '';

            if (field === 'donorId') {
                if (value === '' || !this.donors.some((donor) => donor.id === value)) {
                    error = 'Select a registered donor from the directory.';
                }
            } else if (field === 'donationDate') {
                if (value === '') {
                    error = 'Donation date is required.';
                } else if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                    error = 'Enter a valid donation date.';
                } else {
                    const parsed = new Date(`${value}T00:00:00`);
                    if (Number.isNaN(parsed.getTime())) {
                        error = 'Enter a valid donation date.';
                    }
                }
            } else if (field === 'donationType') {
                if (!VALID_DONATION_TYPES.includes(value)) {
                    error = 'Select Whole Blood, Plasma, or Platelets.';
                }
            } else if (field === 'quantityMl') {
                const numeric = Number(value);
                if (value === '' || !Number.isFinite(numeric)) {
                    error = 'Quantity in ml is required.';
                } else if (!Number.isInteger(numeric) || numeric < 150 || numeric > 800) {
                    error = 'Enter a whole-number volume between 150 ml and 800 ml.';
                }
            } else if (field === 'collectionLocation') {
                if (value === '') {
                    error = 'Collection location is required.';
                } else if (value.length < 3) {
                    error = 'Collection location must be at least 3 characters.';
                }
            } else if (field === 'staffMember') {
                if (value === '') {
                    error = 'Attending staff member is required.';
                } else if (value.length < 2) {
                    error = 'Enter the responsible staff member name.';
                }
            } else if (field === 'screeningStatus') {
                if (!VALID_SCREENING_STATUSES.includes(value)) {
                    error = 'Explicitly select Pending, Passed, or Failed screening status.';
                }
            } else if (field === 'screeningNotes') {
                if (value.length > 1000) {
                    error = 'Screening notes cannot exceed 1000 characters.';
                }
            } else if (field === 'collectionStatus') {
                if (!VALID_COLLECTION_STATUSES.includes(value)) {
                    error = 'Select Scheduled, Completed, or Cancelled collection status.';
                }
            } else if (field === 'collectionNotes') {
                if (value.length > 1000) {
                    error = 'Collection notes cannot exceed 1000 characters.';
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
                'donorId',
                'donationDate',
                'donationType',
                'quantityMl',
                'collectionLocation',
                'staffMember',
                'screeningStatus',
                'screeningNotes',
                'collectionStatus',
                'collectionNotes',
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
                    message: 'Please review the highlighted fields before recording this donation log entry.',
                };
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const donor = this.selectedDonor;
            const donorSummary = donor
                ? `${donor.name} (${donor.id}, ${donor.bloodGroup})`
                : this.form.donorId;

            if (this.mode === 'edit') {
                this.feedback = {
                    type: 'success',
                    title: 'Donation log updated (Preview mode)',
                    message: `Changes for ${this.donationId ?? 'donation'} — ${donorSummary}, ${this.form.donationType} (${this.form.quantityMl} ml) with screening "${this.form.screeningStatus}" and collection "${this.form.collectionStatus}" — passed validation.`,
                };
            } else {
                this.feedback = {
                    type: 'success',
                    title: 'Donation record validated (Preview mode)',
                    message: `${donorSummary} — ${this.form.donationType} (${this.form.quantityMl} ml) logged with explicit screening status "${this.form.screeningStatus}" and collection status "${this.form.collectionStatus}". Backend persistence will store this record in the database phase.`,
                };
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        resetForm() {
            this.form = { ...this.snapshot };
            this.donorSearch = '';
            this.donorDropdownOpen = false;
            this.errors = {};
            this.feedback = null;
        },

        dismissFeedback() {
            this.feedback = null;
        },
    };
}

