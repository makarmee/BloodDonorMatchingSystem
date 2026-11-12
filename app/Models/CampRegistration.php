<?php

namespace App\Models;

use Database\Factories\CampRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('camp_registrations', key: 'id', keyType: 'int')]
#[Fillable([
    'registration_code',
    'camp_id',
    'donor_id',
    'appointment_id',
    'registered_by_user_id',
    'registration_date',
    'is_walk_in',
    'attendance_status',
    'registered_at',
    'checked_in_at',
    'cancelled_at',
])]
class CampRegistration extends Model
{
    /** @use HasFactory<CampRegistrationFactory> */
    use HasFactory;

    /**
     * Valid camp registration attendance statuses.
     *
     * @var list<string>
     */
    public const array ATTENDANCE_STATUSES = [
        'Registered',
        'Confirmed',
        'Attended',
        'No-show',
        'Cancelled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_date' => 'date',
            'is_walk_in' => 'boolean',
            'active_registration_guard' => 'boolean',
            'registered_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Donation camp to which this registration belongs.
     */
    public function camp(): BelongsTo
    {
        return $this->belongsTo(DonationCamp::class, 'camp_id');
    }

    /**
     * Donor enrolled by this registration.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    /**
     * Optional donor portal appointment synchronized with this camp registration.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(DonorAppointment::class, 'appointment_id');
    }

    /**
     * Staff or portal user who recorded this registration.
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /**
     * Scope query to active non-cancelled camp registrations.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('attendance_status', '<>', 'Cancelled');
    }
}
