<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class HospitalVerificationService
{
    /**
     * Transition a Pending or Suspended hospital to Verified status.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function verifyHospital(Hospital $hospital, User $actor): Hospital
    {
        $this->authorizeAdmin($actor);

        return DB::transaction(function () use ($hospital, $actor): Hospital {
            /** @var Hospital $lockedHospital */
            $lockedHospital = Hospital::query()
                ->whereKey($hospital->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = (string) $lockedHospital->verification_status;

            if ($previousStatus === 'Verified') {
                throw ValidationException::withMessages([
                    'hospital' => sprintf(
                        'Hospital [%s] is already verified.',
                        $lockedHospital->hospital_code
                    ),
                ]);
            }

            if (! in_array($previousStatus, ['Pending', 'Suspended'], true)) {
                throw ValidationException::withMessages([
                    'hospital' => sprintf(
                        'Hospital [%s] cannot be verified from status [%s].',
                        $lockedHospital->hospital_code,
                        $previousStatus
                    ),
                ]);
            }

            $lockedHospital->forceFill([
                'verification_status' => 'Verified',
                'is_active' => true,
                'verified_by_user_id' => $actor->id,
                'verified_at' => now(),
            ])->save();

            Log::info("Hospital {$lockedHospital->hospital_code} verified by Admin #{$actor->id}", [
                'hospital_id' => $lockedHospital->id,
                'hospital_code' => $lockedHospital->hospital_code,
                'previous_status' => $previousStatus,
                'new_status' => 'Verified',
                'acting_user_id' => $actor->id,
                'admin_user_id' => $actor->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            return $lockedHospital->fresh(['verifiedBy', 'hospitalUsers.user']);
        }, 3);
    }

    /**
     * Transition an active, Verified hospital to Suspended status.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function suspendHospital(Hospital $hospital, User $actor, ?string $reason = null): Hospital
    {
        $this->authorizeAdmin($actor);

        return DB::transaction(function () use ($hospital, $actor, $reason): Hospital {
            /** @var Hospital $lockedHospital */
            $lockedHospital = Hospital::query()
                ->whereKey($hospital->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = (string) $lockedHospital->verification_status;

            if ($previousStatus !== 'Verified') {
                throw ValidationException::withMessages([
                    'hospital' => sprintf(
                        'Hospital [%s] cannot be suspended from status [%s]. Only Verified hospitals can be suspended.',
                        $lockedHospital->hospital_code,
                        $previousStatus
                    ),
                ]);
            }

            // Note: Suspension preserves historical verified_by_user_id and verified_at metadata
            $lockedHospital->forceFill([
                'verification_status' => 'Suspended',
                'is_active' => false,
            ])->save();

            Log::info("Hospital {$lockedHospital->hospital_code} suspended by Admin #{$actor->id}", [
                'hospital_id' => $lockedHospital->id,
                'hospital_code' => $lockedHospital->hospital_code,
                'previous_status' => $previousStatus,
                'new_status' => 'Suspended',
                'acting_user_id' => $actor->id,
                'admin_user_id' => $actor->id,
                'timestamp' => now()->toIso8601String(),
                'reason' => $reason,
            ]);

            return $lockedHospital->fresh(['verifiedBy', 'hospitalUsers.user']);
        }, 3);
    }

    /**
     * Ensure the actor is an active user with hospital verification privileges.
     *
     * @throws AuthorizationException
     */
    protected function authorizeAdmin(User $actor): void
    {
        if (! $actor->is_active || ! $actor->hasPermission('hospitals.verify')) {
            throw new AuthorizationException('You are not authorized to manage partner hospital verifications.');
        }
    }
}
