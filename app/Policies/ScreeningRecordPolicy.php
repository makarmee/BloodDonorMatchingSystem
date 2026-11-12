<?php

namespace App\Policies;

use App\Models\User;

class ScreeningRecordPolicy
{
    /**
     * Determine whether the user can record laboratory screening assay results.
     */
    public function recordResult(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('screening.record');
    }

    /**
     * Determine whether the user can initialize or manage screening workflows for a donation.
     */
    public function manage(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('screening.manage');
    }
}
