<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'unit', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Resolved once per slug; the slug lives in `role`. */
    private ?Role $accessRole = null;

    private ?string $accessRoleSlug = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The role row this account points at.
     *
     * Not an Eloquent relation on purpose: `role` is already an attribute, so
     * a relation of that name would be shadowed by it.
     */
    public function accessRole(): ?Role
    {
        if ($this->accessRoleSlug !== $this->role || $this->accessRole === null) {
            $this->accessRole = $this->role ? Role::query()->where('slug', $this->role)->first() : null;
            $this->accessRoleSlug = $this->role;
        }

        return $this->accessRole;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->accessRole()?->permissions ?? [];
    }

    public function hasPermission(string $ability): bool
    {
        return in_array($ability, $this->permissions(), true);
    }

    /**
     * Which side of the maintenance desk this account writes from.
     *
     * Falls back to the old rule (anything but `operator` is the service desk)
     * so an install whose roles table has not been seeded still behaves.
     */
    public function deskSide(): string
    {
        return $this->accessRole()?->desk_side ?? ($this->role === 'operator' ? 'operator' : 'cs');
    }

    public function roleLabel(): string
    {
        return $this->accessRole()?->name ?? ucfirst((string) $this->role);
    }

    public function sideLabel(): string
    {
        return config('access.sides')[$this->deskSide()] ?? $this->deskSide();
    }
}
