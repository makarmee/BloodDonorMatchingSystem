<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('donation_camps', key: 'id', keyType: 'int')]
#[Fillable([
    'camp_code',
    'name',
    'description',
    'organizer_name',
    'organizer_contact',
    'organizer_email',
    'created_by_user_id',
    'venue_name',
    'full_address',
    'city',
    'location_notes',
    'start_date',
    'end_date',
    'start_time',
    'end_time',
    'capacity',
    'registration_deadline',
    'allow_walk_in',
    'donor_requirements',
    'additional_notes',
    'status',
])]
class DonationCamp extends Model
{
    /**
     * Valid mobile camp lifecycle statuses.
     *
     * @var list<string>
     */
    public const array STATUSES = ['Draft', 'Upcoming', 'Ongoing', 'Completed', 'Cancelled'];

    /**
     * Camp statuses visible on public portal pages.
     *
     * @var list<string>
     */
    public const array PUBLIC_STATUSES = ['Upcoming', 'Ongoing'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'capacity' => 'integer',
            'registration_deadline' => 'date',
            'allow_walk_in' => 'boolean',
        ];
    }

    /**
     * Staff user who created this donation camp.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * All donor registrations logged for this camp, including cancelled history.
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(CampRegistration::class, 'camp_id');
    }

    /**
     * Non-cancelled active registrations counting toward camp capacity.
     */
    public function activeRegistrations(): HasMany
    {
        return $this->hasMany(CampRegistration::class, 'camp_id')
            ->where('attendance_status', '<>', 'Cancelled');
    }

    /**
     * Donor appointments scheduled for this donation camp.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(DonorAppointment::class, 'donation_camp_id');
    }

    /**
     * Donations collected at this donation camp.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'camp_id');
    }

    /**
     * Immutable status transition history entries for this camp.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(CampStatusHistory::class, 'camp_id');
    }

    /**
     * Scope query to public-facing upcoming and ongoing camps.
     */
    #[Scope]
    protected function published(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }

    /**
     * Scope query to camps with a specific status.
     */
    #[Scope]
    protected function withStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}

