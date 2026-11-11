const VALID_BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
const VALID_GENDERS = ['Female', 'Male', 'Other'];
const VALID_AVAILABILITY = ['Available', 'Temporarily Unavailable', 'Inactive'];

export function donorsDirectory(initial = {}) {
    return {
        donors: Array.isArray(initial.donors) ? initial.donors : [],
        search: '',
        bloodGroupFilter: 'all',
        eligibilityFilter: 'all',
        accountFilter: 'all',
        currentPage: 1,
        perPage: 5,

        init() {
            const resetPage = () => {
                this.currentPage = 1;
            };

            this.$watch('search', resetPage);
            this.$watch('bloodGroupFilter', resetPage);
            this.$watch('eligibilityFilter', resetPage);
            this.$watch('accountFilter', resetPage);
            this.$watch('perPage', resetPage);
        },

        get filteredIndices() {
            const query = this.search.trim().toLowerCase();

            return this.donors.reduce((matches, donor, index) => {
                const name = String(donor.name ?? '').toLowerCase();
                const id = String(donor.id ?? '').toLowerCase();
                const phone = String(donor.phone ?? '').toLowerCase();

                const matchesSearch =
                    query === '' ||
                    name.includes(query) ||
                    id.includes(query) ||
                    phone.includes(query);

                const matchesBloodGroup =
                    this.bloodGroupFilter === 'all' ||
                    donor.bloodGroup === this.bloodGroupFilter;

                const matchesEligibility =
                    this.eligibilityFilter === 'all' ||
                    donor.eligibilityStatus === this.eligibilityFilter;

                const matchesAccount =
                    this.accountFilter === 'all' ||
                    donor.accountStatus === this.accountFilter;

                if (matchesSearch && matchesBloodGroup && matchesEligibility && matchesAccount) {
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
                this.eligibilityFilter !== 'all' ||
                this.accountFilter !== 'all'
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
            this.eligibilityFilter = 'all';
            this.accountFilter = 'all';
            this.currentPage = 1;
        },
    };
}

export function donorForm(initial = {}) {
    const initialForm = {
        name: initial.form?.name ?? '',
        email: initial.form?.email ?? '',
        phone: initial.form?.phone ?? '',
        gender: initial.form?.gender ?? '',
        dateOfBirth: initial.form?.dateOfBirth ?? '',
        bloodGroup: initial.form?.bloodGroup ?? '',
        address: initial.form?.address ?? '',
        emergencyContact: initial.form?.emergencyContact ?? '',
        medicalNotes: initial.form?.medicalNotes ?? '',
        availabilityStatus: initial.form?.availabilityStatus ?? 'Available',
    };

    return {
        mode: initial.mode === 'edit' ? 'edit' : 'create',
        donorId: initial.donorId ?? null,
        snapshot: { ...initialForm },
        form: { ...initialForm },
        errors: {},
        feedback: null,

        validateField(field) {
            const value = String(this.form[field] ?? '').trim();
            let error = '';

            if (field === 'name') {
                if (value === '') {
                    error = 'Full name is required.';
                } else if (value.length < 2) {
                    error = 'Full name must be at least 2 characters.';
                }
            } else if (field === 'email') {
                if (value === '') {
                    error = 'Email address is required.';
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    error = 'Enter a valid email address.';
                }
            } else if (field === 'phone') {
                const digitsOnly = value.replace(/\D/g, '');
                if (value === '') {
                    error = 'Phone number is required.';
                } else if (!/^\+?[0-9\s\-().]{7,20}$/.test(value) || digitsOnly.length < 7) {
                    error = 'Enter a valid contact phone number (at least 7 digits).';
                }
            } else if (field === 'gender') {
                if (!VALID_GENDERS.includes(value)) {
                    error = 'Please select a gender.';
                }
            } else if (field === 'dateOfBirth') {
                if (value === '') {
                    error = 'Date of birth is required.';
                } else {
                    const parsed = new Date(`${value}T00:00:00`);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);

                    if (Number.isNaN(parsed.getTime())) {
                        error = 'Enter a valid date of birth.';
                    } else if (parsed >= today) {
                        error = 'Date of birth must be in the past.';
                    } else {
                        let age = today.getFullYear() - parsed.getFullYear();
                        const monthDiff = today.getMonth() - parsed.getMonth();
                        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < parsed.getDate())) {
                            age -= 1;
                        }
                        if (age < 16) {
                            error = 'Donor must be at least 16 years old to register.';
                        } else if (age > 110) {
                            error = 'Enter a valid date of birth.';
                        }
                    }
                }
            } else if (field === 'bloodGroup') {
                if (!VALID_BLOOD_GROUPS.includes(value)) {
                    error = 'Select one of the 8 standard blood groups.';
                }
            } else if (field === 'address') {
                if (value === '') {
                    error = 'Residential address is required.';
                } else if (value.length < 5) {
                    error = 'Enter a complete street or city address.';
                }
            } else if (field === 'emergencyContact') {
                if (value === '') {
                    error = 'Emergency contact details are required.';
                } else if (value.length < 5) {
                    error = 'Include emergency contact name and phone number.';
                }
            } else if (field === 'medicalNotes') {
                if (value.length > 1000) {
                    error = 'Medical notes cannot exceed 1000 characters.';
                }
            } else if (field === 'availabilityStatus') {
                if (!VALID_AVAILABILITY.includes(value)) {
                    error = 'Select an availability status.';
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
                'name',
                'email',
                'phone',
                'gender',
                'dateOfBirth',
                'bloodGroup',
                'address',
                'emergencyContact',
                'medicalNotes',
                'availabilityStatus',
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
                    message: 'Please complete or correct the highlighted donor fields before continuing.',
                };
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const donorLabel = this.form.name.trim();
            const bloodGroupLabel = this.form.bloodGroup;

            if (this.mode === 'edit') {
                this.feedback = {
                    type: 'success',
                    title: 'Donor profile validated (Preview mode)',
                    message: `Updates for ${donorLabel}${this.donorId ? ` (${this.donorId})` : ''} passed client-side checks. Final eligibility remains governed by verified clinical screening rules and backend persistence.`,
                };
            } else {
                this.feedback = {
                    type: 'success',
                    title: 'Donor registration validated (Preview mode)',
                    message: `${donorLabel} (${bloodGroupLabel}) passed registration checks. Eligibility status starts as Pending Review until verified medical screening rules run on the backend.`,
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

