<?php

namespace App\Models;

use Database\Factories\DonorEligibilityRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('donor_eligibility_records', key: 'id', keyType: 'int')]
#[Fillable([
    'donor_id',
    'eligibility_status',
    'reason',
    'clearance_source',
    'reviewed_at',
    'next_eligible_date',
    'reviewed_by_user_id',
    'is_current',
])]
class DonorEligibilityRecord extends Model
{
    /** @use HasFactory<DonorEligibilityRecordFactory> */
    use HasFactory;

    /**
     * Valid eligibility clearance statuses.
     *
     * @var list<string>
     */
    public const array ELIGIBILITY_STATUSES = Donor::ELIGIBILITY_STATUSES;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'date',
            'next_eligible_date' => 'date',
            'is_current' => 'boolean',
            'current_record_guard' => 'boolean',
        ];
    }

    /**
     * Donor evaluated by this eligibility record.
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }

    /**
     * Medical officer or staff user who reviewed this decision.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Scope query to active (`is_current = true`) authoritative eligibility records.
     */
    #[Scope]
    protected function current(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /**
     * Scope query to eligibility records with a specific status.
     */
    #[Scope]
    protected function withStatus(Builder $query, string $status): Builder
    {
        return $query->where('eligibility_status', $status);
    }
}
