<?php

namespace App\Models;

use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Table('donations', key: 'id', keyType: 'int')]
#[Fillable([
    'donation_code',
    'donor_id',
    'appointment_id',
    'camp_id',
    'center_id',
    'collection_location_name',
    'recorded_by_user_id',
    'staff_member_name',
    'blood_group',
    'blood_component_id',
    'donation_date',
    'collected_at',
    'quantity_ml',
    'collection_status',
    'screening_status',
    'collection_notes',
    'screening_notes',
    'released_to_inventory_at',
    'approved_by_user_id',
])]
class Donation extends Model
{
    /** @use HasFactory<DonationFactory> */
    use HasFactory;

    /**
     * Valid blood group values.
     *
     * @var list<string>
     */
    public const array BLOOD_GROUPS = Donor::BLOOD_GROUPS;

    /**
     * Valid physical draw statuses.
     *
     * @var list<string>
     */
    public const array COLLECTION_STATUSES = ['Scheduled', 'Completed', 'Cancelled'];

    /**
     * Valid overall donation screening gate statuses.
     *
     * @var list<string>
     */
    public const array SCREENING_STATUSES = ['Pending', 'Passed', 'Failed'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'donation_date' => 'date',
            'collected_at' => 'datetime',
            'quantity_ml' => 'integer',
            'released_to_inventory_at' => 'datetime',
        ];
    }

    /**
     * Donor who provided this donation.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    /**
     * Optional scheduled appointment fulfilled by this donation.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(DonorAppointment::class, 'appointment_id');
    }

    /**
     * Optional mobile camp where this donation was collected.
     */
    public function camp(): BelongsTo
    {
        return $this->belongsTo(DonationCamp::class, 'camp_id');
    }

    /**
     * Optional fixed donation center where this donation was collected.
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(DonationCenter::class, 'center_id');
    }

    /**
     * Primary blood component collected during this donation.
     */
    public function bloodComponent(): BelongsTo
    {
        return $this->belongsTo(BloodComponent::class, 'blood_component_id');
    }

    /**
     * Staff user who recorded this donation.
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Authorized officer who approved inventory release.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Laboratory screening panel records for this donation.
     */
    public function screeningRecords(): HasMany
    {
        return $this->hasMany(ScreeningRecord::class, 'donation_id');
    }

    /**
     * Serialized blood units produced from this donation (primary and derived).
     */
    public function bloodUnits(): HasMany
    {
        return $this->hasMany(BloodUnit::class, 'donation_id');
    }

    /**
     * Primary unsplit blood unit generated from this collection.
     */
    public function primaryBloodUnit(): HasOne
    {
        return $this->hasOne(BloodUnit::class, 'donation_id')->whereNull('parent_unit_id');
    }

    /**
     * Scope query to completed blood collections.
     */
    #[Scope]
    protected function completed(Builder $query): Builder
    {
        return $query->where('collection_status', 'Completed');
    }

    /**
     * Scope query to donations whose overall screening status has passed.
     */
    #[Scope]
    protected function screeningPassed(Builder $query): Builder
    {
        return $query->where('screening_status', 'Passed');
    }

    /**
     * Scope query to donations not yet released into available inventory.
     */
    #[Scope]
    protected function unreleased(Builder $query): Builder
    {
        return $query->whereNull('released_to_inventory_at');
    }
}
