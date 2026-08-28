<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\PreDeal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Правила от 25.08.2026: аудит предсделок и смета дизайнера на сделке. */
class PreDealAuditAndEstimateTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->baia->id);

        return $u;
    }

    public function test_predeal_changes_are_audited_and_visible_in_history(): void
    {
        $mgr = $this->user('manager');
        $lot = PreDeal::create([
            'company_id' => $this->baia->id, 'user_id' => $mgr->id, 'action' => 'participation',
            'lot_number' => 'L-1', 'customer' => 'ТОО Заказчик', 'product' => 'Стол', 'contract_sum' => 100000, 'status' => 'new',
        ]);
        $this->actingAs($mgr);
        $lot->update(['contract_sum' => 150000]);

        $this->assertTrue(AuditLog::where('table_name', 'pre_deals')->where('record_id', $lot->id)->where('action', 'created')->exists());
        $this->assertTrue(AuditLog::where('table_name', 'pre_deals')->where('record_id', $lot->id)
            ->where('field_name', 'contract_sum')->where('user_id', $mgr->id)->exists());

        $this->actingAs($mgr)->withSession(['company_id' => $this->baia->id])
            ->getJson(route('preDeals.history', $lot->id))
            ->assertOk()->assertJsonFragment(['field_name' => 'contract_sum']);
    }

    public function test_designer_uploads_estimate_and_deal_exposes_it(): void
    {
        Storage::fake('local');
        $designer = $this->user('designer');
        $deal = Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-001', 'name' => 'Сделка', 'company_name' => 'ТОО',
            'budget' => 100000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $this->user('manager')->id,
        ]);

        $this->actingAs($designer)->withSession(['company_id' => $this->baia->id])
            ->post(route('documents.store'), [
                'documentable_type' => 'deal', 'documentable_id' => $deal->id, 'kind' => 'estimate',
                'file' => UploadedFile::fake()->image('smeta.jpg'),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $doc = Document::where('documentable_id', $deal->id)->first();
        $this->assertSame('estimate', $doc->kind);

        $this->actingAs($this->user('financist'))->withSession(['company_id' => $this->baia->id])
            ->get(route('deals.show', $deal->id))
            ->assertOk()->assertInertia(fn ($page) => $page->where('estimate.id', $doc->id));
    }

    // Ошибка с прода 27.08.2026: пустая «доля партнёра» → Column 'partner_pct' cannot be null.
    public function test_store_with_empty_numeric_fields_saves_zero(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->withSession(['company_id' => $this->baia->id])
            ->post(route('preDeals.store'), [
                'action' => 'participation', 'lot_number' => 'L-777', 'customer' => 'ТОО', 'product' => 'Костюм',
                'contract_sum' => 725000, 'purchase_price' => 245000,
                'partner_pct' => '', 'delivery' => '', 'assembly' => 20000, 'commission' => '',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $lot = PreDeal::where('lot_number', 'L-777')->firstOrFail();
        $this->assertSame(0.0, (float) $lot->partner_pct);
        $this->assertSame(0.0, (float) $lot->delivery);
    }
}
