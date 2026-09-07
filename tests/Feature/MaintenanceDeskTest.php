<?php

namespace Tests\Feature;

use App\Models\MaintenanceTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The maintenance desk: asking for work, talking it through, and the log of
 * what was done.
 */
class MaintenanceDeskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => 'operator', 'name' => 'Operator Uji']);
    }

    private function desk(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Layanan Uji']);
    }

    public function test_a_request_opens_a_job_and_starts_the_conversation(): void
    {
        $response = $this->actingAs($this->operator())
            ->postJson('/api/maintenance/requests', [
                'title' => 'Sirene hilir tidak berbunyi',
                'station' => 'ews-01',
                'type' => 'korektif',
                'priority' => 'tinggi',
                'body' => 'Uji mingguan gagal, tidak ada suara sama sekali.',
            ])
            ->assertCreated();

        $ticket = $response->json('data');

        $this->assertSame('permintaan', $ticket['source']);
        $this->assertSame('EWS Hilir', $ticket['station']);
        $this->assertCount(1, $ticket['messages']);
        $this->assertSame('operator', $ticket['messages'][0]['role']);

        $this->assertDatabaseHas('maintenance_tasks', [
            'id' => $ticket['id'],
            'priority' => 'tinggi',
            'source' => 'permintaan',
        ]);
    }

    public function test_both_sides_write_into_the_same_thread(): void
    {
        $task = MaintenanceTask::query()->firstOrFail();

        $this->actingAs($this->operator())
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Alat mati sejak pagi.'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'operator');

        $this->actingAs($this->desk())
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Teknisi berangkat siang ini.'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'cs');

        $this->assertSame(2, $task->messages()->count());
        $this->assertNotNull($task->fresh()->last_message_at);
    }

    public function test_a_message_names_its_author_so_the_reader_can_spot_their_own(): void
    {
        $task = MaintenanceTask::query()->firstOrFail();
        $operator = $this->operator();
        $colleague = User::factory()->create(['role' => 'operator', 'name' => 'Rekan Operator']);

        $mine = $this->actingAs($operator)
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Saya yang menulis.'])
            ->assertCreated()
            ->json('data');

        $theirs = $this->actingAs($colleague)
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Rekan yang menulis.'])
            ->assertCreated()
            ->json('data');

        // Both sit on the operator side, so only the author id tells them apart.
        $this->assertSame('operator', $mine['role']);
        $this->assertSame('operator', $theirs['role']);
        $this->assertSame($operator->id, $mine['user_id']);
        $this->assertSame($colleague->id, $theirs['user_id']);
    }

    public function test_a_request_is_marked_with_who_asked_for_it(): void
    {
        $operator = $this->operator();

        $ticket = $this->actingAs($operator)
            ->postJson('/api/maintenance/requests', ['title' => 'Ganti sekring panel surya'])
            ->assertCreated()
            ->json('data');

        $this->assertSame($operator->id, $ticket['requester_id']);
    }

    public function test_the_badge_counts_only_what_the_other_side_wrote(): void
    {
        $task = MaintenanceTask::query()->firstOrFail();
        $operator = $this->operator();

        // The seeder already leaves the desk with unanswered words, so this
        // counts the change rather than an absolute number.
        $before = $this->actingAs($operator)->getJson('/api/maintenance/tickets')->assertOk()->json('unread');

        $this->actingAs($this->desk())
            ->postJson("/api/maintenance/{$task->id}/messages", ['body' => 'Sudah dijadwalkan.']);

        $after = $this->actingAs($operator)->getJson('/api/maintenance/tickets')->json('unread');

        $this->assertSame($before + 1, $after);

        // Reading that thread clears its share of the badge.
        $unreadInThread = $task->messages()->whereNull('read_at')->where('author_role', 'cs')->count();

        $this->actingAs($operator)->postJson("/api/maintenance/{$task->id}/read")
            ->assertOk()
            ->assertJsonPath('unread', $after - $unreadInThread);
    }

    public function test_finished_work_is_listed_per_instrument(): void
    {
        $user = $this->operator();

        $history = $this->actingAs($user)->getJson('/api/maintenance/history')->assertOk()->json('data');

        $this->assertNotEmpty($history, 'Seeder menyediakan pekerjaan selesai.');

        foreach ($history as $row) {
            $this->assertArrayHasKey('completed_at', $row);
            $this->assertArrayHasKey('station', $row);
        }

        $code = collect($history)->firstWhere('station_code', '!=', null)['station_code'];
        $filtered = $this->actingAs($user)->getJson("/api/maintenance/history?station={$code}")->json('data');

        $this->assertNotEmpty($filtered);
        $this->assertSame([$code], collect($filtered)->pluck('station_code')->unique()->values()->all());
    }

    public function test_a_request_without_a_title_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/maintenance/requests', ['body' => 'tanpa judul'])
            ->assertStatus(422);
    }
}
