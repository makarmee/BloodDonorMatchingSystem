<?php

namespace App\Models;

use Database\Factories\RequestFulfillmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('request_fulfillments', key: 'id', keyType: 'int')]
#[Fillable([
    'fulfillment_code',
    'blood_request_id',
    'dispatched_by_user_id',
    'dispatched_blood_group',
    'blood_component_id',
    'units_dispatched',
    'dispatch_date',
    'dispatched_at',
    'courier_reference',
    'delivery_status',
    'delivered_at',
    'received_by_name',
    'notes',
    'is_compatibility_override',
    'override_approved_by_user_id',
    'override_approved_at',
    'override_reason',
])]
class RequestFulfillment extends Model
{
    /** @use HasFactory<RequestFulfillmentFactory> */
    use HasFactory;

    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Valid cold-chain delivery statuses.
     *
     * @var list<string>
     */
    public const array DELIVERY_STATUSES = ['Dispatched', 'In Transit', 'Delivered', 'Returned'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units_dispatched' => 'integer',
            'dispatch_date' => 'date',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'is_compatibility_override' => 'boolean',
            'override_approved_at' => 'datetime',
        ];
    }

    /**
     * Parent blood request satisfied by this dispatch batch.
     */
    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class, 'blood_request_id');
    }

    /**
     * Staff user who dispatched this shipment.
     */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    /**
     * Actual blood component dispatched in this shipment.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Medical officer who authorized compatibility override, if applicable.
     */
    public function overrideApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_approved_by_user_id');
    }

    /**
     * Serialized blood unit allocations included in this dispatch batch.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryAllocation::class, 'fulfillment_id');
    }

    /**
     * Scope query to fulfillments issued under an authorized compatibility override.
     */
    #[Scope]
    protected function compatibilityOverrides(Builder $query): Builder
    {
        return $query->where('is_compatibility_override', true);
    }
}
