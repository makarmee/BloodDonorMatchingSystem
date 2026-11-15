<?php

namespace App\Services;

use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountRegistrationService
{
    private const int MINIMUM_DONOR_AGE = 16;

    private const int MAXIMUM_DONOR_AGE = 110;

    public function __construct(
        private ReferenceCodeGenerator $referenceCodeGenerator,
        private DonorEligibilityService $donorEligibilityService,
        private ServiceInputNormalizer $inputNormalizer,
    ) {}

    /**
     * Self-register a new donor account, linked donor profile, and initial onboarding eligibility record.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     blood_group?: ?string,
     *     date_of_birth?: mixed
     * }  $attributes
     *
     * @throws ValidationException
     */
    public function registerDonor(array $attributes): User
    {
        $name = $this->inputNormalizer->normalizeRequiredText(
            $attributes['name'] ?? null,
            150,
            'name',
            'Full name is required and may not exceed 150 characters.'
        );

        $email = $this->normalizeEmail($attributes['email'] ?? null);
        $password = is_string($attributes['password'] ?? null) ? $attributes['password'] : '';

        if ($password === '') {
            throw ValidationException::withMessages([
                'password' => 'A password is required for account registration.',
            ]);
        }

        $bloodGroup = $this->normalizeOptionalBloodGroup($attributes['blood_group'] ?? null);
        $dateOfBirth = $this->normalizeOptionalDateOfBirth($attributes['date_of_birth'] ?? null);

        try {
            return DB::transaction(function () use ($name, $email, $password, $bloodGroup, $dateOfBirth): User {
                $userEmailExists = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists();

                // Never auto-claim or link existing walk-in donors (`user_id IS NULL`) during unverified registration.
                $donorEmailExists = Donor::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists();

                if ($userEmailExists || $donorEmailExists) {
                    throw ValidationException::withMessages([
                        'email' => 'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
                    ]);
                }

                $donorRole = Role::query()->bySlug('donor')->firstOrFail();

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);

                $user->roles()->attach($donorRole->id, [
                    'assigned_at' => now(),
                ]);

                $donor = Donor::query()->create([
                    'donor_code' => $this->referenceCodeGenerator->generate(Donor::class, 'donor_code', 'DNR-'),
                    'user_id' => $user->id,
                    'full_name' => $name,
                    'email' => $email,
                    'blood_group' => $bloodGroup,
                    'date_of_birth' => $dateOfBirth,
                    'availability_status' => 'Available',
                    'account_status' => 'Active',
                    'registered_at' => CarbonImmutable::today()->toDateString(),
                ]);

                $donor->profile_completed = $this->hasCompletedRequiredProfile($donor);
                $donor->save();

                $this->donorEligibilityService->initializeOnboardingEligibility(
                    donor: $donor,
                    actor: null,
                    status: 'Pending Review',
                    reason: 'Initial donor self-registration awaiting clinical eligibility review.',
                    clearanceSource: 'Donor Self-Registration',
                );

                return $user->load(['roles', 'donor']);
            }, 3);
        } catch (QueryException $e) {
            if ($this->isRegistrationEmailUniqueConstraintViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => 'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Self-register a new partner hospital facility, primary coordinator account, and coordinator link.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     organization_name: string,
     *     address?: ?string,
     *     city?: ?string,
     *     phone?: ?string
     * }  $attributes
     *
     * @throws ValidationException
     */
    public function registerHospital(array $attributes): User
    {
        $coordinatorName = $this->inputNormalizer->normalizeRequiredText(
            $attributes['name'] ?? null,
            150,
            'name',
            'Coordinator name is required and may not exceed 150 characters.'
        );

        $organizationName = $this->inputNormalizer->normalizeRequiredText(
            $attributes['organization_name'] ?? null,
            191,
            'organization_name',
            'Hospital or clinic name is required and may not exceed 191 characters.'
        );

        $email = $this->normalizeEmail($attributes['email'] ?? null);
        $password = is_string($attributes['password'] ?? null) ? $attributes['password'] : '';

        if ($password === '') {
            throw ValidationException::withMessages([
                'password' => 'A password is required for account registration.',
            ]);
        }

        $address = $this->inputNormalizer->normalizeOptionalText(
            $attributes['address'] ?? null,
            2000,
            'address'
        );

        $city = $this->inputNormalizer->normalizeOptionalText(
            $attributes['city'] ?? null,
            100,
            'city'
        );

        $phone = $this->inputNormalizer->normalizeOptionalText(
            $attributes['phone'] ?? null,
            32,
            'phone'
        );

        try {
            return DB::transaction(function () use (
                $coordinatorName,
                $organizationName,
                $email,
                $password,
                $address,
                $city,
                $phone
            ): User {
                $userEmailExists = User::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists();

                $donorEmailExists = Donor::query()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists();

                if ($userEmailExists || $donorEmailExists) {
                    throw ValidationException::withMessages([
                        'email' => 'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
                    ]);
                }

                $hospitalRole = Role::query()->bySlug('hospital')->firstOrFail();

                $user = User::query()->create([
                    'name' => $coordinatorName,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);

                $user->roles()->attach($hospitalRole->id, [
                    'assigned_at' => now(),
                ]);

                $hospital = Hospital::query()->create([
                    'hospital_code' => $this->referenceCodeGenerator->generate(Hospital::class, 'hospital_code', 'HSP-'),
                    'name' => $organizationName,
                    'address' => $address,
                    'city' => $city,
                    'primary_contact_name' => $coordinatorName,
                    'primary_phone' => $phone,
                    'primary_email' => $email,
                    'verification_status' => 'Pending',
                    'verified_by_user_id' => null,
                    'verified_at' => null,
                    'is_active' => true,
                ]);

                HospitalUser::query()->create([
                    'hospital_id' => $hospital->id,
                    'user_id' => $user->id,
                    'contact_name' => $coordinatorName,
                    'work_phone' => $phone,
                    'work_email' => $email,
                    'is_primary_contact' => true,
                    'is_active' => true,
                ]);

                return $user->load(['roles', 'hospitalUser.hospital']);
            }, 3);
        } catch (QueryException $e) {
            if ($this->isRegistrationEmailUniqueConstraintViolation($e)) {
                throw ValidationException::withMessages([
                    'email' => 'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Determine whether a QueryException represents an account registration email uniqueness violation.
     */
    private function isRegistrationEmailUniqueConstraintViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? $e->getCode());
        $vendorCode = isset($errorInfo[1]) ? (int) $errorInfo[1] : null;
        $driverMessage = (string) ($errorInfo[2] ?? $e->getMessage());

        // MySQL 8.4: ER_DUP_ENTRY is vendor code 1062 under SQLSTATE 23000
        $isMysqlDuplicate = $sqlState === '23000' && $vendorCode === 1062;

        // SQLite: SQLITE_CONSTRAINT (19) or SQLSTATE 23000 with explicit UNIQUE constraint failure
        $isSqliteDuplicate = ($sqlState === '23000' || $vendorCode === 19)
            && str_contains($driverMessage, 'UNIQUE constraint failed');

        if (! $isMysqlDuplicate && ! $isSqliteDuplicate) {
            return false;
        }

        $lowerMessage = strtolower($driverMessage);

        // Ensure the unique constraint failure specifically targets user or donor email columns
        return str_contains($lowerMessage, 'users.email')
            || str_contains($lowerMessage, 'donors.email')
            || str_contains($lowerMessage, 'users_email_unique')
            || str_contains($lowerMessage, 'donors_email_unique');
    }

    /**
     * Normalize and validate a required account email address.
     *
     * @throws ValidationException
     */
    private function normalizeEmail(mixed $email): string
    {
        $normalized = is_string($email) ? Str::lower(trim($email)) : '';

        if ($normalized === '' || mb_strlen($normalized) > 191 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'email' => 'Please enter a valid email address.',
            ]);
        }

        return $normalized;
    }

    /**
     * Normalize an optional blood group selection against allowed ABO/Rh values.
     *
     * @throws ValidationException
     */
    private function normalizeOptionalBloodGroup(mixed $bloodGroup): ?string
    {
        if ($bloodGroup === null) {
            return null;
        }

        $trimmed = is_string($bloodGroup) ? trim($bloodGroup) : '';

        if ($trimmed === '') {
            return null;
        }

        if (! in_array($trimmed, Donor::BLOOD_GROUPS, true)) {
            throw ValidationException::withMessages([
                'blood_group' => 'Please select a valid blood group.',
            ]);
        }

        return $trimmed;
    }

    /**
     * Parse and age-validate an optional donor date of birth against clinical age bounds (16-110).
     *
     * @throws ValidationException
     */
    private function normalizeOptionalDateOfBirth(mixed $dateOfBirth): ?string
    {
        $parsedDate = $this->inputNormalizer->parseOptionalDate($dateOfBirth, 'date_of_birth');

        if ($parsedDate === null) {
            return null;
        }

        $today = CarbonImmutable::today();
        $minAllowedDate = $today->subYears(self::MAXIMUM_DONOR_AGE)->toDateString();
        $maxAllowedDate = $today->subYears(self::MINIMUM_DONOR_AGE)->toDateString();

        if ($parsedDate < $minAllowedDate) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Donor age must not exceed '.self::MAXIMUM_DONOR_AGE.' years.',
            ]);
        }

        if ($parsedDate > $maxAllowedDate) {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Donors must be at least '.self::MINIMUM_DONOR_AGE.' years old to register.',
            ]);
        }

        return $parsedDate;
    }

    /**
     * Determine whether all eight core donor profile fields are populated.
     */
    private function hasCompletedRequiredProfile(Donor $donor): bool
    {
        foreach (Donor::REQUIRED_PROFILE_FIELDS as $field) {
            $value = $donor->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                return false;
            }
        }

        return true;
    }
}
