<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Company;
use App\Models\User;
use App\Support\LiveStamp;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Живые обновления без WebSocket: штамп двигают события, опрос — 0 SQL, 304 по ETag. */
class LiveStampTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'manager'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach(Company::where('code', 'BAIA')->firstOrFail()->id);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_version_endpoint_supports_etag_304_and_requires_auth(): void
    {
        // Без входа — не отдаём штамп (редирект на логин или 401 — как настроен guard).
        $this->assertContains($this->get(route('live.version'))->getStatusCode(), [302, 401]);

        $u = $this->user();
        $first = $this->actingAs($u)->getJson(route('live.version'))->assertOk()
            ->assertJsonStructure(['chat', 'notifications', 'tasks']);
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->actingAs($u)->withHeaders(['If-None-Match' => $etag])
            ->getJson(route('live.version'))->assertStatus(304);
    }

    public function test_chat_message_bumps_participants_and_notification_bumps_recipient(): void
    {
        $a = $this->user();
        $b = $this->user();
        $outsider = $this->user();

        $chat = Chat::create(['type' => 'personal', 'name' => 'a-b']);
        $chat->participants()->attach([$a->id, $b->id]);

        $before = LiveStamp::get($b->id)['chat'];
        usleep(2000);
        ChatMessage::create(['chat_id' => $chat->id, 'user_id' => $a->id, 'message' => 'привет']);
        $this->assertNotSame($before, LiveStamp::get($b->id)['chat'], 'участник получает сдвиг штампа chat');
        $this->assertSame(0, LiveStamp::get($outsider->id)['chat'], 'посторонний — нет');

        $u = $this->user();
        $u->notify(new \App\Notifications\DealStageChanged(
            \App\Models\Deal::create([
                'company_id' => Company::where('code', 'BAIA')->firstOrFail()->id, 'number' => 'BAIA-001', 'name' => 'Сделка',
                'company_name' => 'ТОО', 'budget' => 1, 'status' => 'active',
                'deal_stage_id' => \App\Models\DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $u->id,
            ]), 'Дизайн'));
        $this->assertGreaterThan(0, LiveStamp::get($u->id)['notifications'], 'уведомление сдвигает штамп notifications');
    }
}
