<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('screening_records', key: 'id', keyType: 'int')]
#[Fillable([
    'screening_code',
    'donation_id',
    'test_type_id',
    'test_date',
    'screening_result',
    'is_confirmatory',
    'performed_by_user_id',
    'lab_staff_name',
    'assay_method',
    'specimen_tube_code',
    'test_notes',
    'collected_at',
    'logged_at',
    'result_recorded_at',
])]
class ScreeningRecord extends Model
{
    /**
     * Valid individual lab screening result values.
     *
     * @var list<string>
     */
    public const array SCREENING_RESULTS = ['Pending', 'Passed', 'Failed'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_date' => 'date',
            'is_confirmatory' => 'boolean',
            'collected_at' => 'datetime',
            'logged_at' => 'datetime',
            'result_recorded_at' => 'datetime',
        ];
    }

    /**
     * Donation tested by this screening record.
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }

    /**
     * Assay test type executed for this screening record.
     */
    public function testType(): BelongsTo
    {
        return $this->belongsTo(ScreeningTestType::class, 'test_type_id');
    }

    /**
     * Lab staff user who performed or logged this test.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * Scope query to pending screening records.
     */
    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('screening_result', 'Pending');
    }

    /**
     * Scope query to passed screening records.
     */
    #[Scope]
    protected function passed(Builder $query): Builder
    {
        return $query->where('screening_result', 'Passed');
    }

    /**
     * Scope query to failed screening records.
     */
    #[Scope]
    protected function failed(Builder $query): Builder
    {
        return $query->where('screening_result', 'Failed');
    }
}

