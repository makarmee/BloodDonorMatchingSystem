<?php

namespace App\Policies;

use App\Models\BloodUnit;
use App\Models\User;

class BloodUnitPolicy
{
    /**
     * Determine whether the user can reserve a specific available blood unit.
     */
    public function reserve(User $user, BloodUnit $bloodUnit): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can release a reserved blood unit back to available stock.
     */
    public function releaseReservation(User $user, BloodUnit $bloodUnit): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can discard a serialized blood unit.
     */
    public function discard(User $user, BloodUnit $bloodUnit): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can mark an expired available blood unit as Expired.
     */
    public function markExpired(User $user, BloodUnit $bloodUnit): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user holds active `inventory.adjust` permission.
     */
    private function canAdjustInventory(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('inventory.adjust');
    }
}
