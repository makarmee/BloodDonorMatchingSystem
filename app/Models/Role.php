<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Table('roles', key: 'id', keyType: 'int')]
#[Fillable(['name', 'slug', 'description'])]
class Role extends Model
{
    /**
     * Users assigned this role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')
            ->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    /**
     * Permissions granted to this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id')
            ->withPivot('created_at');
    }

    /**
     * Scope query to a role with the given slug.
     */
    #[Scope]
    protected function bySlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }
}

