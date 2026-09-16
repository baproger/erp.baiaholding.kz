<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Жалоба от 18.09.2026: «Оплачено» по авансу срабатывает несколько раз —
 * касса задваивается; удаление оплаченного счёта кассу не возвращает.
 */
class AdvanceDoublePayTest extends TestCase
{
    use RefreshDatabase;

    private Company $baia;

    private User $fin;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->baia = Company::where('code', 'BAIA')->firstOrFail();
        $this->fin = User::factory()->create();
        $this->fin->assignRole('financist');
        $this->fin->companies()->attach($this->baia->id);
        $this->deal = Deal::create([
            'company_id' => $this->baia->id, 'number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО',
            'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $this->fin->id,
        ]);
    }

    private function bank(): float
    {
        return (float) app(FinanceService::class)->companyBalances($this->baia->id)['bank'];
    }

    public function test_double_click_pay_creates_single_payment(): void
    {
        $inv = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-1', 'amount' => 100000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);

        $payload = ['invoice_id' => $inv->id, 'amount' => 100000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'bank'];

        // Двойной клик: два одинаковых запроса подряд.
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), $payload);
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), $payload);

        $this->assertSame(1, Payment::count(), 'повторный клик не должен создать второй платёж');
        $this->assertSame(100000.0, $this->bank(), 'касса должна вырасти один раз');
    }

    public function test_deleting_paid_invoice_returns_cash(): void
    {
        $inv = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-2', 'amount' => 100000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), [
            'invoice_id' => $inv->id, 'amount' => 100000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ]);
        $this->assertSame(100000.0, $this->bank());

        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])
            ->delete(route('invoices.destroy', $inv->id));

        $this->assertSame(0.0, $this->bank(), 'после удаления оплаченного счёта касса должна вернуться');
        $this->assertSame(0, Payment::count(), 'платежи удалённого счёта должны удалиться');
    }

    /** Правило от 19.09.2026: аванс по сделке не может превысить сумму договора. */
    public function test_payment_cannot_exceed_deal_budget(): void
    {
        // Счёт выписан на 600k при договоре 500k — платёж режется по договору.
        $inv = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-3', 'amount' => 600000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);

        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), [
            'invoice_id' => $inv->id, 'amount' => 600000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());

        // Ровно сумма договора — проходит; сверх — уже нет.
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), [
            'invoice_id' => $inv->id, 'amount' => 500000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ])->assertSessionHasNoErrors();
        $this->assertSame(500000.0, $this->bank());
    }

    public function test_new_invoice_refused_when_deal_fully_paid(): void
    {
        $inv = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-4', 'amount' => 500000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), [
            'invoice_id' => $inv->id, 'amount' => 500000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ]);

        // Оплачено = сумме договора → новый счёт не выставляется.
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('invoices.store'), [
            'invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id, 'amount' => 100000, 'status' => 'sent',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(1, Invoice::count());
    }

    /** Правило от 19.09.2026 (уточнение): сами СЧЕТА не могут превысить договор. */
    public function test_invoices_cannot_exceed_deal_budget(): void
    {
        $post = fn ($amount) => $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])
            ->post(route('invoices.store'), ['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
                'amount' => $amount, 'status' => 'sent']);

        // Счёт на 1.4 суммы договора — отказ сразу.
        $post(700000)->assertSessionHasErrors('amount');
        $this->assertSame(0, Invoice::count());

        // 300k + 200k = 500k (ровно договор) — можно; дальше ни тенге.
        $post(300000)->assertSessionHasNoErrors();
        $post(200000)->assertSessionHasNoErrors();
        $post(1)->assertSessionHasErrors('amount');
        $this->assertSame(2, Invoice::count());

        // Поднять сумму счёта сверх договора через редактирование тоже нельзя.
        $first = Invoice::orderBy('id')->first();
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])
            ->put(route('invoices.update', $first->id), ['amount' => 400000, 'status' => 'sent'])
            ->assertSessionHasErrors('amount');
        // А в пределах договора — можно (отменённый счёт лимит освобождает).
        Invoice::orderBy('id', 'desc')->first()->update(['status' => 'cancelled']);
        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])
            ->put(route('invoices.update', $first->id), ['amount' => 400000, 'status' => 'sent'])
            ->assertSessionHasNoErrors();
    }

    /** Два счёта одной сделки: суммарный аванс всё равно ограничен договором. */
    public function test_two_invoices_cannot_overpay_deal(): void
    {
        $inv1 = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-5', 'amount' => 300000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);
        $inv2 = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal->id,
            'number' => 'INV-6', 'amount' => 300000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);

        $pay = fn ($inv, $amount) => $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])
            ->post(route('payments.store'), ['invoice_id' => $inv->id, 'amount' => $amount,
                'payment_date' => now()->toDateString(), 'payment_method' => 'bank']);

        $pay($inv1, 300000)->assertSessionHasNoErrors();
        // По второму счёту остаток 300k, но по договору свободно только 200k.
        $pay($inv2, 300000)->assertSessionHasErrors('amount');
        $pay($inv2, 200000)->assertSessionHasNoErrors();
        $this->assertSame(500000.0, $this->bank());

        // Договор выбран полностью — дальше ни тенге.
        $pay($inv2, 1)->assertSessionHasErrors('amount');
    }

    /** Сделка без суммы договора (budget 0) — ограничение не мешает работе. */
    public function test_zero_budget_deal_not_blocked(): void
    {
        $free = Deal::create(['company_id' => $this->baia->id, 'number' => 'BAIA-002', 'name' => 'Y',
            'company_name' => 'ТОО', 'budget' => 0, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $this->fin->id]);
        $inv = Invoice::create(['invoiceable_type' => 'deal', 'invoiceable_id' => $free->id,
            'number' => 'INV-7', 'amount' => 50000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);

        $this->actingAs($this->fin)->withSession(['company_id' => $this->baia->id])->post(route('payments.store'), [
            'invoice_id' => $inv->id, 'amount' => 50000, 'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ])->assertSessionHasNoErrors();
    }
}
