<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\MonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accounts and what they may do.
 *
 * Two screens in one page: the list of people who can sign in, and the roles
 * that decide what each of them can reach. The role also carries the side of
 * the maintenance desk its holders write from, which is why an operator and
 * the service desk see the same thread from opposite ends.
 */
class AccessController extends Controller
{
    public function __construct(
        private readonly MonitoringService $monitoring,
    ) {}

    public function index(Request $request): View
    {
        return view('pages.users', [
            'boot' => $this->monitoring->bootPayload($request->user()),
            'users' => User::query()->orderBy('name')->get(),
            'roles' => Role::query()->withCount('users')->orderBy('name')->get(),
            'catalogue' => Role::catalogue(),
            'sides' => config('access.sides'),
        ]);
    }

    /* ------------------------------------------------------------------ *
     |  Accounts
     * ------------------------------------------------------------------ */

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $this->userRules($request);

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'unit' => $data['unit'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active', true),
            'email_verified_at' => now(),
        ]);

        return back()->with('status', 'Pengguna '.$data['name'].' ditambahkan.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $this->userRules($request, $user);

        $active = $request->boolean('is_active');

        // Locking yourself out is the one mistake this screen must not allow.
        if ($user->is($request->user())) {
            $keepsAccess = Role::query()->where('slug', $data['role'])->first()?->grants('users.manage') ?? false;

            if (! $keepsAccess || ! $active) {
                throw ValidationException::withMessages([
                    'role' => 'Akun sendiri tidak dapat kehilangan akses kelola pengguna atau dinonaktifkan.',
                ])->errorBag('user');
            }
        }

        $this->guardLastAdministrator($user, $data['role'], $active);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'unit' => $data['unit'] ?? null,
            'is_active' => $active,
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return back()->with('status', 'Perubahan untuk '.$user->name.' disimpan.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'Akun yang sedang dipakai tidak dapat dihapus.',
            ])->errorBag('user');
        }

        $this->guardLastAdministrator($user, null, false);

        $name = $user->name;
        $user->delete();

        return back()->with('status', 'Pengguna '.$name.' dihapus.');
    }

    /* ------------------------------------------------------------------ *
     |  Roles
     * ------------------------------------------------------------------ */

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('role', [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:180'],
            'desk_side' => ['required', Rule::in(array_keys(config('access.sides')))],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $slug = Str::slug($data['name']);

        if (Role::query()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        Role::query()->create([
            'slug' => $slug,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'desk_side' => $data['desk_side'],
            'permissions' => Role::sanitise($data['permissions'] ?? []),
            'is_system' => false,
        ]);

        return back()->with('status', 'Peran '.$data['name'].' dibuat.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $data = $request->validateWithBag('role', [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:180'],
            'desk_side' => ['required', Rule::in(array_keys(config('access.sides')))],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'desk_side' => $data['desk_side'],
            // The administrator role always carries the whole catalogue: it is
            // the role that hands out access, so it cannot be narrowed into a
            // state where nobody can widen it again.
            'permissions' => $role->slug === 'admin'
                ? Role::abilities()
                : Role::sanitise($data['permissions'] ?? []),
        ])->save();

        return back()->with('status', 'Hak akses '.$role->name.' diperbarui.');
    }

    public function destroyRole(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'Peran bawaan sistem tidak dapat dihapus.',
            ])->errorBag('role');
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Peran ini masih dipakai. Pindahkan penggunanya lebih dulu.',
            ])->errorBag('role');
        }

        $name = $role->name;
        $role->delete();

        return back()->with('status', 'Peran '.$name.' dihapus.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function userRules(Request $request, ?User $user = null): array
    {
        return $request->validateWithBag('user', [
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
            'unit' => ['nullable', 'string', 'max:120'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:72'],
        ]);
    }

    /**
     * Refuse a change that would leave nobody able to reach this screen.
     *
     * `$nextRole` is the role the account is moving to, or null when it is
     * being deleted.
     */
    private function guardLastAdministrator(User $user, ?string $nextRole, bool $stayingActive): void
    {
        $keeps = $nextRole
            ? (Role::query()->where('slug', $nextRole)->first()?->grants('users.manage') ?? false) && $stayingActive
            : false;

        if ($keeps || ! $user->hasPermission('users.manage')) {
            return;
        }

        $admins = User::query()
            ->where('is_active', true)
            ->whereIn('role', Role::query()->get()->filter->grants('users.manage')->pluck('slug'))
            ->where('id', '!=', $user->id)
            ->count();

        if ($admins === 0) {
            throw ValidationException::withMessages([
                'role' => 'Harus ada minimal satu akun aktif yang dapat mengelola pengguna.',
            ])->errorBag('user');
        }
    }
}
