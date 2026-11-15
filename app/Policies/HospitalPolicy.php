<?php

namespace App\Policies;

use App\Models\Hospital;
use App\Models\User;

class HospitalPolicy
{
    /**
     * Determine whether the user can view the hospital verification queue.
     */
    public function viewAny(User $user): bool
    {
        return $this->canVerifyHospitals($user);
    }

    /**
     * Determine whether the user can view hospital management details.
     */
    public function view(User $user, Hospital $hospital): bool
    {
        return $this->canVerifyHospitals($user);
    }

    /**
     * Determine whether the user can verify a hospital.
     */
    public function verify(User $user, Hospital $hospital): bool
    {
        return $this->canVerifyHospitals($user);
    }

    /**
     * Determine whether the user can suspend a hospital.
     */
    public function suspend(User $user, Hospital $hospital): bool
    {
        return $this->canVerifyHospitals($user);
    }

    /**
     * Check if the user is an active user with hospital verification privileges.
     */
    protected function canVerifyHospitals(User $user): bool
    {
        return (bool) ($user->is_active && $user->hasPermission('hospitals.verify'));
    }
}
