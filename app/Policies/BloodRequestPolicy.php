<?php

namespace App\Policies;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BloodRequestPolicy
{
    /**
     * Determine whether the user can submit a new blood request for the given hospital.
     */
    public function create(User $user, ?Hospital $hospital = null): Response|bool
    {
        if (! $user->is_active) {
            return Response::deny(__('Your account is deactivated.'));
        }

        if ($user->hasPermission('requests.fulfill')) {
            return Response::allow();
        }

        if (! $user->hasRole('hospital')) {
            return Response::deny(__('Only verified hospital representatives and authorized staff may create blood requests.'));
        }

        $coordinatorLink = $user->hospitalUser()->where('is_active', true)->with('hospital')->first();
        if (! $coordinatorLink || ! $coordinatorLink->hospital) {
            return Response::deny(__('Your user profile is not linked to any hospital.'));
        }

        $targetHospital = $hospital ?? $coordinatorLink->hospital;

        if (! $targetHospital->is_active) {
            return Response::deny(__('Your hospital account has been deactivated.'));
        }

        if ($targetHospital->verification_status !== 'Verified') {
            return Response::deny(__('Your hospital account is currently pending administrative verification. Only verified partner hospitals may create blood requests.'));
        }

        if (! $this->isActiveHospitalCoordinatorFor($user, $targetHospital)) {
            return Response::deny(__('You are not authorized as an active coordinator for this hospital.'));
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can view the blood request details.
     */
    public function view(User $user, BloodRequest $bloodRequest): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasPermission('requests.fulfill')) {
            return true;
        }

        if (! $user->hasRole('hospital')) {
            return false;
        }

        return $this->isActiveHospitalCoordinatorFor($user, $bloodRequest->hospital);
    }

    /**
     * Determine whether the user can approve or reject blood requests.
     */
    public function review(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        return $this->hasFulfillPermission($user);
    }

    /**
     * Determine whether the user can modify open blood request details or requested quantities.
     */
    public function update(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        return $this->hasFulfillPermission($user);
    }

    /**
     * Determine whether the user can reserve blood units for a blood request.
     */
    public function allocate(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        return $this->hasFulfillPermission($user);
    }

    /**
     * Determine whether the user can release reserved unit allocations on a blood request.
     */
    public function releaseAllocation(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        return $this->hasFulfillPermission($user);
    }

    /**
     * Determine whether the user can dispatch blood units against a blood request.
     */
    public function dispatch(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        return $this->hasFulfillPermission($user);
    }

    /**
     * Determine whether the user can authorize a compatibility override during reservation or dispatch.
     */
    public function overrideCompatibility(User $user, ?BloodRequest $bloodRequest = null): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('requests.override_compatibility');
    }

    /**
     * Determine whether the user can cancel the given blood request.
     *
     * Admin and staff with `requests.fulfill` may cancel any non-terminal request;
     * active hospital coordinators may cancel only their own facility's Pending requests.
     */
    public function cancel(User $user, BloodRequest $bloodRequest): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasPermission('requests.fulfill')) {
            return true;
        }

        if (! $user->hasRole('hospital') || $bloodRequest->status !== 'Pending') {
            return false;
        }

        return $user->hospitalUser()
            ->where('hospital_id', $bloodRequest->hospital_id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Determine whether the active user holds the documented `requests.fulfill` permission.
     */
    private function hasFulfillPermission(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return $user->hasPermission('requests.fulfill');
    }

    /**
     * Determine whether the user has an active coordinator link to the specified hospital.
     */
    private function isActiveHospitalCoordinatorFor(User $user, Hospital $hospital): bool
    {
        return $user->hospitalUser()
            ->where('hospital_id', $hospital->getKey())
            ->where('is_active', true)
            ->exists();
    }
}
