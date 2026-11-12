<?php

namespace App\Policies;

use App\Models\Donor;
use App\Models\User;

class DonorPolicy
{
    /**
     * Determine whether the user can view sensitive medical profile details for the donor.
     */
    public function viewMedical(User $user, Donor $donor): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'staff', 'lab_officer'])
            || $user->hasPermission('donors.view_medical');
    }

    /**
     * Determine whether the user can record a donor eligibility assessment or deferral.
     */
    public function assessEligibility(User $user, Donor $donor, ?string $targetStatus = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'staff'])) {
            return true;
        }

        return $targetStatus === 'Not Eligible' && $user->hasRole('lab_officer');
    }
}

