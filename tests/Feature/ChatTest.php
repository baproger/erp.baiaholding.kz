<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_admin_can_rename_and_delete_group(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Старое', 'participants' => []])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($admin)->put(route('chat.update', $chat), ['name' => 'Новое', 'participants' => []])->assertRedirect();
        $this->assertEquals('Новое', $chat->fresh()->name);

        $this->actingAs($admin)->delete(route('chat.destroy', $chat))->assertRedirect();
        $this->assertDatabaseMissing('chats', ['id' => $chat->id]);
    }

    public function test_employee_cannot_edit_or_delete_group(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $emp = User::factory()->create();
        $emp->assignRole('employee');

        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Группа', 'participants' => [$emp->id]])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($emp)->put(route('chat.update', $chat), ['name' => 'Взлом'])->assertForbidden();
        $this->actingAs($emp)->delete(route('chat.destroy', $chat))->assertForbidden();
    }

    public function test_global_chat_cannot_be_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('chat.index')); // ensures global chat
        $global = Chat::where('type', 'global')->first();

        $this->actingAs($admin)->delete(route('chat.destroy', $global))->assertForbidden();
        $this->assertDatabaseHas('chats', ['id' => $global->id]);
    }

    public function test_can_send_and_download_file_attachment(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), [
            'message' => '', 'file' => UploadedFile::fake()->create('отчёт.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $msg = ChatMessage::first();
        $this->assertNotEmpty($msg->attachments);
        Storage::disk('local')->assertExists($msg->attachments[0]['path']);

        $this->actingAs($u)->get(route('chat.attachment', [$msg->id, 0]))->assertOk();
    }

    public function test_attachments_endpoint_lists_chat_files(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), ['file' => UploadedFile::fake()->create('смета.xlsx', 30)]);

        $this->actingAs($u)->getJson(route('chat.attachments', $chat))
            ->assertOk()->assertJsonCount(1, 'attachments')->assertJsonFragment(['name' => 'смета.xlsx']);
    }

    public function test_message_delete_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(); $admin->assignRole('admin');
        $author = User::factory()->create(); $author->assignRole('employee');
        $other = User::factory()->create(); $other->assignRole('employee');

        $this->actingAs($author)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();
        $this->actingAs($author)->post(route('chat.send', $chat), ['message' => 'Моё сообщение']);
        $msg = ChatMessage::first();

        // Another regular employee cannot delete someone else's message.
        $this->actingAs($other)->delete(route('chat.messages.destroy', $msg))->assertForbidden();
        // Admin can delete any message.
        $this->actingAs($admin)->delete(route('chat.messages.destroy', $msg))->assertOk();
        $this->assertDatabaseMissing('chat_messages', ['id' => $msg->id]);
    }
}
