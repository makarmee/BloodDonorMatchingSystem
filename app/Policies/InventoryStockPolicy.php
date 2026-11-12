<?php

namespace App\Policies;

use App\Models\InventoryStock;
use App\Models\User;

class InventoryStockPolicy
{
    /**
     * Determine whether the user can perform manual inventory adjustments.
     */
    public function adjust(User $user, ?InventoryStock $inventoryStock = null): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can reserve available blood units from an inventory bucket.
     */
    public function reserve(User $user, ?InventoryStock $inventoryStock = null): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can release reserved blood units back to available stock.
     */
    public function releaseReservation(User $user, ?InventoryStock $inventoryStock = null): bool
    {
        return $this->canAdjustInventory($user);
    }

    /**
     * Determine whether the user can discard units tracked by an inventory bucket.
     */
    public function discard(User $user, ?InventoryStock $inventoryStock = null): bool
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
