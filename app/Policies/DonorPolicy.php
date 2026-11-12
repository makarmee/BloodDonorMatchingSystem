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

        return $user->hasRole('admin') || $user->hasPermission('donors.view_medical');
    }

    /**
     * Determine whether the user can record a donor eligibility assessment or deferral.
     *
     * Until an explicit clinical eligibility permission or medical officer role is added
     * to documented authorization rules, only active admin users may alter donor eligibility.
     */
    public function assessEligibility(User $user, Donor $donor, ?string $targetStatus = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin');
    }
}
