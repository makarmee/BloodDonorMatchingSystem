<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('donation_centers', key: 'id', keyType: 'int')]
#[Fillable([
    'center_code',
    'name',
    'address',
    'city',
    'operating_hours',
    'default_slot_capacity',
    'is_active',
])]
class DonationCenter extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_slot_capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Donor appointments scheduled at this fixed donation center.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(DonorAppointment::class, 'donation_center_id');
    }

    /**
     * Donations collected at this fixed donation center.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'center_id');
    }

    /**
     * Scope query to active fixed donation centers.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to centers located in the given city.
     */
    #[Scope]
    protected function inCity(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }
}

