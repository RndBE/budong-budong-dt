<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DamSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/digital-twin')->assertRedirect('/login');
    }

    public function test_the_login_screen_renders_the_current_scene(): void
    {
        $this->seed([DamSeeder::class, StationSeeder::class]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Bendungan Budong Budong', escape: false)
            ->assertSee('assets/panorama/preview/base-dam-', escape: false);
    }

    public function test_demo_credentials_are_hidden_in_production(): void
    {
        config(['app.env' => 'production']);
        $this->seed([DamSeeder::class, StationSeeder::class]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Akun demo')
            ->assertDontSee('value="admin@bwssulawesi5.go.id"', escape: false);
    }

    public function test_an_operator_can_sign_in_and_lands_on_the_map(): void
    {
        $this->seed([DamSeeder::class, StationSeeder::class]);

        $user = User::factory()->create([
            'email' => 'operator@bwssulawesi5.go.id',
            'password' => Hash::make('rahasia'),
        ]);

        $this->post('/login', [
            'email' => 'operator@bwssulawesi5.go.id',
            'password' => 'rahasia',
        ])->assertRedirect(route('twin'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'operator@bwssulawesi5.go.id']);

        $this->post('/login', [
            'email' => 'operator@bwssulawesi5.go.id',
            'password' => 'salah',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
