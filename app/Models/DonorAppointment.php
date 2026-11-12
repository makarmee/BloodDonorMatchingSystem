<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('donor_appointments', key: 'id', keyType: 'int')]
#[Fillable([
    'appointment_code',
    'donor_id',
    'donation_component_id',
    'donation_center_id',
    'donation_camp_id',
    'appointment_date',
    'slot_time',
    'status',
    'notes',
    'prep_summary',
    'cancelled_at',
    'cancellation_reason',
])]
class DonorAppointment extends Model
{
    /**
     * Valid donor appointment lifecycle statuses.
     *
     * @var list<string>
     */
    public const array STATUSES = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'active_slot_guard' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Donor who holds this appointment.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    /**
     * Blood component scheduled for collection during this appointment.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'donation_component_id');
    }

    /**
     * Fixed donation center targeted by this appointment, when not bound to a camp.
     */
    public function donationCenter(): BelongsTo
    {
        return $this->belongsTo(DonationCenter::class, 'donation_center_id');
    }

    /**
     * Mobile donation camp targeted by this appointment, when not bound to a center.
     */
    public function donationCamp(): BelongsTo
    {
        return $this->belongsTo(DonationCamp::class, 'donation_camp_id');
    }

    /**
     * Longitudinal status transition history entries for this appointment.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class, 'appointment_id');
    }

    /**
     * Optional camp registration synchronized with this camp-targeted appointment.
     */
    public function campRegistration(): HasOne
    {
        return $this->hasOne(CampRegistration::class, 'appointment_id');
    }

    /**
     * Optional completed or scheduled donation resulting from this appointment.
     */
    public function donation(): HasOne
    {
        return $this->hasOne(Donation::class, 'appointment_id');
    }

    /**
     * Scope query to active non-cancelled donor appointments.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('status', '<>', 'Cancelled');
    }

    /**
     * Scope query to appointments with a given status.
     */
    #[Scope]
    protected function withStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}

