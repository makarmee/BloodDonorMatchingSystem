<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('blood_request_status_histories', key: 'id', keyType: 'int')]
#[WithoutTimestamps]
#[Fillable([
    'blood_request_id',
    'event_name',
    'previous_status',
    'new_status',
    'units_affected',
    'actor_user_id',
    'actor_name',
    'notes',
    'occurred_at',
])]
class BloodRequestStatusHistory extends Model
{
    /**
     * Valid blood request statuses.
     *
     * @var list<string>
     */
    public const array STATUSES = BloodRequest::STATUSES;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_affected' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Blood request documented by this timeline entry.
     */
    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'blood_request_id');
    }

    /**
     * User who triggered this status transition.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

