<?php

namespace Tests\Feature;

use App\Models\MaintenanceTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Accounts, roles, and what a role opens.
 *
 * The role is the single switch: it decides which screens answer, which API
 * calls are allowed, and which side of the maintenance desk an account writes
 * from.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Admin Uji']);
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => 'operator', 'name' => 'Operator Uji']);
    }

    public function test_only_an_administrator_reaches_the_access_screen(): void
    {
        $this->actingAs($this->admin())->get('/pengguna')->assertOk()->assertSee('Pengguna &amp; Hak Akses', escape: false);
        $this->actingAs($this->operator())->get('/pengguna')->assertForbidden();
    }

    public function test_an_administrator_creates_an_account_with_a_role(): void
    {
        $this->actingAs($this->admin())
            ->post('/pengguna', [
                'name' => 'Teknisi Baru',
                'email' => 'teknisi.baru@bwssulawesi5.go.id',
                'role' => 'teknisi',
                'unit' => 'Layanan Teknis',
                'password' => 'rahasia-sekali',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'teknisi.baru@bwssulawesi5.go.id')->firstOrFail();

        $this->assertSame('teknisi', $user->role);
        $this->assertTrue($user->is_active);
        // The role, not the account, decides the side of the desk.
        $this->assertSame('cs', $user->deskSide());
        $this->assertTrue(Hash::check('rahasia-sekali', $user->password));
    }

    public function test_an_account_is_edited_and_deleted_from_the_list(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'operator', 'name' => 'Petugas Lama', 'unit' => 'OP']);

        $this->actingAs($admin)
            ->put("/pengguna/{$user->id}", [
                'name' => 'Petugas Baru',
                'email' => $user->email,
                'role' => 'teknisi',
                'unit' => 'Layanan Teknis',
                // The box is left unticked: the account is put on hold.
            ])
            ->assertRedirect();

        $user->refresh();

        $this->assertSame('Petugas Baru', $user->name);
        $this->assertSame('teknisi', $user->role);
        $this->assertFalse($user->is_active);

        // The password is only replaced when one is typed.
        $before = $user->password;

        $this->actingAs($admin)->put("/pengguna/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => '1',
        ]);

        $this->assertSame($before, $user->fresh()->password);

        $this->actingAs($admin)->delete("/pengguna/{$user->id}")->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_a_role_is_created_edited_and_deleted(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/peran', [
                'name' => 'Teknisi Lapangan',
                'description' => 'Mengerjakan perbaikan di lokasi.',
                'desk_side' => 'cs',
                'permissions' => ['maintenance.view', 'maintenance.reply', 'tidak.ada'],
            ])
            ->assertRedirect();

        $role = Role::query()->where('slug', 'teknisi-lapangan')->firstOrFail();

        // Anything outside the catalogue is dropped rather than stored.
        $this->assertSame(['maintenance.view', 'maintenance.reply'], $role->permissions);
        $this->assertSame('cs', $role->desk_side);

        $this->actingAs($admin)
            ->put("/peran/{$role->slug}", [
                'name' => 'Teknisi Lapangan',
                'desk_side' => 'operator',
                'permissions' => ['maintenance.view'],
            ])
            ->assertRedirect();

        $role->refresh();

        $this->assertSame('operator', $role->desk_side);
        $this->assertSame(['maintenance.view'], $role->permissions);

        $this->actingAs($admin)->delete("/peran/{$role->slug}")->assertRedirect();
        $this->assertDatabaseMissing('roles', ['slug' => 'teknisi-lapangan']);
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin())
            ->delete('/peran/operator')
            ->assertSessionHasErrors([], null, 'role');

        $this->assertDatabaseHas('roles', ['slug' => 'operator']);
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'nonaktif@bwssulawesi5.go.id',
            'password' => Hash::make('rahasia-sekali'),
            'role' => 'operator',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => 'nonaktif@bwssulawesi5.go.id',
            'password' => 'rahasia-sekali',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_changing_a_role_changes_what_its_holders_may_do(): void
    {
        $task = MaintenanceTask::query()->firstOrFail();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Masih bisa menulis.'])
            ->assertCreated();

        $role = Role::query()->where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => array_values(array_diff($role->permissions, ['maintenance.reply']))]);

        // A later request loads the account again, and with it the new rules.
        $this->actingAs($operator->fresh())
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Sekarang tidak boleh.'])
            ->assertForbidden();
    }

    public function test_the_desk_side_follows_the_role_not_the_slug(): void
    {
        $task = MaintenanceTask::query()->firstOrFail();

        // A role that answers from the service desk, whatever it is called.
        Role::query()->create([
            'slug' => 'penyelia-lapangan',
            'name' => 'Penyelia Lapangan',
            'desk_side' => 'cs',
            'permissions' => ['maintenance.view', 'maintenance.reply'],
        ]);

        $user = User::factory()->create(['role' => 'penyelia-lapangan', 'name' => 'Penyelia Uji']);

        $this->actingAs($user)
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Teknisi berangkat.'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'cs');

        // And the unread badge counts the other side, seen from that role.
        $this->actingAs($user)
            ->getJson('/api/maintenance/tickets')
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_the_administrator_role_keeps_every_ability(): void
    {
        $this->actingAs($this->admin())
            ->put('/peran/admin', [
                'name' => 'Administrator',
                'desk_side' => 'cs',
                'permissions' => ['maintenance.view'],
            ])
            ->assertRedirect();

        $this->assertSame(Role::abilities(), Role::query()->where('slug', 'admin')->firstOrFail()->permissions);
    }

    public function test_a_role_that_still_has_people_cannot_be_deleted(): void
    {
        User::factory()->create(['role' => 'teknisi']);

        $this->actingAs($this->admin())
            ->delete('/peran/teknisi')
            ->assertSessionHasErrors([], null, 'role');

        $this->assertDatabaseHas('roles', ['slug' => 'teknisi']);
    }

    public function test_the_last_administrator_cannot_lose_access(): void
    {
        $admin = $this->admin();

        // Every other account that could manage users is taken out of the way.
        User::query()->where('id', '!=', $admin->id)->update(['role' => 'operator']);

        $this->actingAs($admin)
            ->put("/pengguna/{$admin->id}", [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'operator',
            ])
            ->assertSessionHasErrors([], null, 'user');

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_the_rail_only_offers_screens_the_role_can_open(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Pengguna &amp; Akses', escape: false);

        $pengawas = User::factory()->create(['role' => 'pengawas']);

        $response = $this->actingAs($pengawas)->get('/dashboard')->assertOk();
        $response->assertDontSee('Pengguna &amp; Akses', escape: false);
    }
}
