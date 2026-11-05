<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_and_read_actions_are_private_and_idempotent(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        NotificationController::send($user->id, 'example', 'assignment', 'Assigned work', ['workspace' => 'delivery', 'page' => 'tasks']);
        NotificationController::send($other->id, 'other', 'assignment', 'Private work', []);
        $id = DB::table('work_notifications')->where('user_id', $user->id)->value('id');
        $otherId = DB::table('work_notifications')->where('user_id', $other->id)->value('id');
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->actingAs($user)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('unread', 1)->assertJsonPath('items.0.message', 'Assigned work');
        $this->postJson('/api/notifications/'.$otherId.'/read')->assertNotFound();
        $this->postJson('/api/notifications/'.$id.'/read')->assertOk()->assertJsonPath('unread', 0);
        $readAt = DB::table('work_notifications')->where('id', $id)->value('read_at');
        NotificationController::send($user->id, 'example', 'assignment', 'Duplicate event', []);
        $this->assertDatabaseHas('work_notifications', ['id' => $id, 'read_at' => $readAt, 'message' => 'Assigned work']);
        $this->postJson('/api/notifications/read-all')->assertOk();
        $this->assertDatabaseHas('work_notifications', ['id' => $otherId, 'read_at' => null]);
    }

    public function test_pagination_keeps_full_unread_count_and_md_can_only_mark_own_updates(): void
    {
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        for ($i = 0; $i < 33; $i++) {
            NotificationController::send($md->id, 'event-'.$i, 'assignment', 'Event '.$i, []);
        }
        $response = $this->actingAs($md)->getJson('/api/notifications')->assertOk()->assertJsonCount(30, 'items')->assertJsonPath('unread', 33);
        $this->getJson('/api/notifications?before='.$response->json('next'))->assertJsonCount(3, 'items')->assertJsonPath('unread', 33)->assertJsonPath('next', null);
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->postJson('/api/planning/plans', [])->assertForbidden();
        $this->getJson('/api/notifications?before=bad')->assertUnprocessable();
        $md->update(['is_active' => false]);
        $this->actingAs($md)->getJson('/api/notifications')->assertForbidden();
    }
}
