<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('appointment_status_histories', key: 'id', keyType: 'int')]
#[WithoutTimestamps]
#[Fillable([
    'appointment_id',
    'event_name',
    'previous_status',
    'new_status',
    'actor_user_id',
    'notes',
    'occurred_at',
])]
class AppointmentStatusHistory extends Model
{
    /**
     * Valid appointment statuses.
     *
     * @var list<string>
     */
    public const array APPOINTMENT_STATUSES = DonorAppointment::STATUSES;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Donor appointment documented by this timeline entry.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(DonorAppointment::class, 'appointment_id');
    }

    /**
     * User who triggered this appointment status change.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

