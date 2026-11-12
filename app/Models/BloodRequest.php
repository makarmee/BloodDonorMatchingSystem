<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('blood_requests', key: 'id', keyType: 'int')]
#[Fillable([
    'request_code',
    'hospital_id',
    'requested_by_user_id',
    'department_or_ward',
    'contact_person_snapshot',
    'contact_phone_snapshot',
    'contact_email_snapshot',
    'blood_group',
    'blood_component_id',
    'requested_units',
    'reserved_units',
    'fulfilled_units',
    'priority',
    'status',
    'request_date',
    'required_date',
    'request_reason',
    'additional_notes',
    'reviewed_by_user_id',
    'reviewed_at',
    'rejection_reason',
    'cancelled_by_user_id',
    'cancelled_at',
    'cancellation_reason',
])]
class BloodRequest extends Model
{
    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Valid clinical triage priority levels.
     *
     * @var list<string>
     */
    public const array PRIORITIES = ['Low', 'Normal', 'High', 'Critical'];

    /**
     * Valid blood request lifecycle statuses.
     *
     * @var list<string>
     */
    public const array STATUSES = [
        'Pending',
        'Approved',
        'Rejected',
        'Partially Fulfilled',
        'Fulfilled',
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
            'requested_units' => 'integer',
            'reserved_units' => 'integer',
            'fulfilled_units' => 'integer',
            'request_date' => 'date',
            'required_date' => 'date',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Verified partner hospital placing this blood request.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    /**
     * Hospital coordinator or staff user who submitted this request.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * Optional specific blood component requested.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Staff user who approved or rejected this request.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * User who cancelled this request.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Immutable status timeline entries for this request.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BloodRequestStatusHistory::class, 'blood_request_id');
    }

    /**
     * Physical dispatch batches fulfilling this request.
     */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(RequestFulfillment::class, 'blood_request_id');
    }

    /**
     * Serialized unit allocations reserved or dispatched for this request.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class, 'blood_request_id');
    }

    /**
     * Scope query to requests matching the given status.
     */
    #[Scope]
    protected function withStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope query to requests eligible for dispatch or reservation fulfillment.
     */
    #[Scope]
    protected function openForFulfillment(Builder $query): Builder
    {
        return $query->whereIn('status', ['Approved', 'Partially Fulfilled']);
    }

    /**
     * Scope query to critical priority requests.
     */
    #[Scope]
    protected function critical(Builder $query): Builder
    {
        return $query->where('priority', 'Critical');
    }
}

