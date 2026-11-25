<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

class StaffPolicy
{
    /**
     * Determine whether the user can view the staff directory.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the user can view a staff member profile.
     */
    public function view(User $user, Staff $staff): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the user can create staff profiles.
     */
    public function create(User $user): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the user can update staff profiles.
     */
    public function update(User $user, Staff $staff): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the user can activate or deactivate a staff profile.
     */
    public function toggleStatus(User $user, Staff $staff): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the user can delete a staff profile.
     */
    public function delete(User $user, Staff $staff): bool
    {
        return $this->canManageStaff($user);
    }

    /**
     * Determine whether the acting user is an active administrator with staff management authority.
     */
    private function canManageStaff(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasRole('admin') || $user->hasPermission('staff.manage');
    }
}
