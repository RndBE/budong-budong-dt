<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of abilities.
 *
 * The catalogue of abilities lives in `config/access.php`; a role only stores
 * the codes it was granted, so a new ability appears on the access screen the
 * moment it is added to the config.
 */
class Role extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'desk_side', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /** Users are joined by slug: `users.role` is the foreign key. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<string, array<string, array{label: string, hint: string}>> */
    public static function catalogue(): array
    {
        return config('access.groups', []);
    }

    /** Every ability code the application knows about. @return list<string> */
    public static function abilities(): array
    {
        return collect(static::catalogue())->flatMap(fn (array $group) => array_keys($group))->all();
    }

    /** Drop anything that is not in the catalogue. @return list<string> */
    public static function sanitise(array $codes): array
    {
        return array_values(array_intersect(static::abilities(), $codes));
    }

    public function sideLabel(): string
    {
        return config('access.sides')[$this->desk_side] ?? $this->desk_side;
    }

    public function grants(string $ability): bool
    {
        return in_array($ability, $this->permissions ?? [], true);
    }
}
