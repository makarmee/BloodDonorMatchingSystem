<?php

namespace App\Policies;

use App\Models\Donation;
use App\Models\User;

class DonationPolicy
{
    /**
     * Determine whether the user can register or schedule a blood donation.
     */
    public function create(User $user): bool
    {
        return $this->canManageCollection($user);
    }

    /**
     * Determine whether the user can mark a scheduled donation as physically completed.
     */
    public function complete(User $user, Donation $donation): bool
    {
        return $this->canManageCollection($user);
    }

    /**
     * Determine whether the user can cancel a scheduled donation prior to collection.
     */
    public function cancel(User $user, Donation $donation): bool
    {
        return $this->canManageCollection($user);
    }

    /**
     * Determine whether the user can create the primary quarantined blood unit for a donation.
     */
    public function createUnit(User $user, Donation $donation): bool
    {
        return $this->canManageCollection($user);
    }

    /**
     * Determine whether the user is explicitly authorized to release a passed donation into available inventory.
     */
    public function releaseToInventory(User $user, Donation $donation): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('screening.release_inventory');
    }

    /**
     * Determine whether the user is an active staff member or administrator managing collections.
     */
    private function canManageCollection(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasAnyRole(['admin', 'staff']);
    }
}
