<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Правило от 13.09.2026: снабженец делает приход товара из своего кабинета,
 * остаток растёт сразу, а ОПЛАТУ подтверждает бухгалтер на «Расходах»
 * (какой кассой платить — решает он). Чек снабженца — необязательный.
 */
class WarehouseSupplierTest extends TestCase
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

    public function test_supplier_receipt_creates_pending_expense_and_stock(): void
    {
        Storage::fake('local');
        $supplier = $this->user('supplier');

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->get(route('warehouse.index'))->assertOk();

        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->post(route('warehouse.receipt'), [
                'name' => 'ЛДСП белый', 'unit' => 'штук', 'quantity' => 10, 'price' => 5000,
                'file' => UploadedFile::fake()->image('nakladnaya.jpg'),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $material = Material::where('name', 'ЛДСП белый')->firstOrFail();
        $this->assertSame(10.0, (float) $material->quantity, 'остаток растёт сразу');

        $e = Expense::latest('id')->firstOrFail();
        $this->assertSame('pending', $e->status, 'оплата ждёт бухгалтера');
        $this->assertNull($e->payment_method, 'кассу выбирает бухгалтер');
        $this->assertSame($supplier->id, $e->responsible_user_id, 'видно, кто привёз');
        $this->assertNotNull($e->file_path, 'накладная снабженца приложена');
        $this->assertSame(50000.0, (float) $e->amount);
        $this->assertStringContainsString('Закуп товара: ЛДСП белый', $e->description);
    }

    public function test_supplier_cannot_pick_cash_register_or_delete(): void
    {
        $supplier = $this->user('supplier');

        // payment_method снабженца игнорируется: заявка всё равно pending.
        $this->actingAs($supplier)->withSession(['company_id' => $this->baia->id])
            ->post(route('warehouse.receipt'), [
                'name' => 'МДФ', 'quantity' => 2, 'price' => 1000, 'payment_method' => 'cash',
            ])->assertRedirect();
        $this->assertSame('pending', Expense::latest('id')->firstOrFail()->status);

        $material = Material::where('name', 'МДФ')->firstOrFail();
        $this->actingAs($supplier)->delete(route('warehouse.materials.destroy', $material->id))->assertForbidden();
    }

    public function test_accountant_receipt_with_cash_stays_immediate(): void
    {
        $fin = $this->user('financist');

        $this->actingAs($fin)->withSession(['company_id' => $this->baia->id])
            ->post(route('warehouse.receipt'), [
                'name' => 'Кромка', 'quantity' => 5, 'price' => 200, 'payment_method' => 'bank',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $e = Expense::latest('id')->firstOrFail();
        $this->assertSame('confirmed', $e->status);
        $this->assertSame('bank', $e->payment_method);
    }

    public function test_manager_cannot_make_receipt(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->withSession(['company_id' => $this->baia->id])
            ->post(route('warehouse.receipt'), ['name' => 'Х', 'quantity' => 1])->assertForbidden();
    }
}
