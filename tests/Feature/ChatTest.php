<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('employee');
        return $u;
    }

    public function test_index_creates_global_chat_and_renders(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'))->assertOk();
        $this->assertEquals(1, Chat::where('type', 'global')->count());
    }

    public function test_send_and_fetch_messages(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index')); // ensures global chat
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), ['message' => 'Привет команда'])->assertRedirect();

        $response = $this->actingAs($u)->getJson(route('chat.messages', $chat));
        $response->assertOk()->assertJsonFragment(['message' => 'Привет команда']);
    }

    public function test_non_participant_cannot_read_group_chat(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create();
        $owner->assignRole('admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('employee');

        $this->actingAs($owner)->post(route('chat.store'), ['type' => 'group', 'name' => 'Приватный', 'participants' => []])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($outsider)->getJson(route('chat.messages', $chat))->assertForbidden();
    }
}
