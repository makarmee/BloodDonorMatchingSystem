<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('camp_status_histories', key: 'id', keyType: 'int')]
#[WithoutTimestamps]
#[Fillable([
    'camp_id',
    'event_name',
    'previous_status',
    'new_status',
    'actor_user_id',
    'actor_name',
    'notes',
    'occurred_at',
])]
class CampStatusHistory extends Model
{
    /**
     * Valid camp statuses.
     *
     * @var list<string>
     */
    public const array CAMP_STATUSES = DonationCamp::STATUSES;

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
     * Donation camp documented by this timeline entry.
     */
    public function camp(): BelongsTo
    {
        return $this->belongsTo(DonationCamp::class, 'camp_id');
    }

    /**
     * User who triggered this camp event or status change.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

