<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role, ?Company $company = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($company) {
            $u->companies()->attach($company->id);
        }

        return $u;
    }

    public function test_financist_receipt_creates_material_and_stock(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $fin = $this->user('financist', $company);

        $this->actingAs($fin)
            ->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['name' => 'ЛДСП 16мм', 'unit' => 'штук', 'quantity' => 50])
            ->assertRedirect();

        $material = Material::where('name', 'ЛДСП 16мм')->first();
        $this->assertNotNull($material);
        $this->assertEquals(50.0, (float) $material->quantity);
        $this->assertSame($company->id, (int) $material->company_id);

        // Повторный приход существующего материала суммирует остаток.
        $this->actingAs($fin)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['material_id' => $material->id, 'quantity' => 25])
            ->assertRedirect();
        $this->assertEquals(75.0, (float) $material->fresh()->quantity);
    }

    public function test_manager_sees_warehouse_but_cannot_receipt(): void
    {
        $company = Company::where('code', 'BAIA')->firstOrFail();
        $mgr = $this->user('manager', $company);

        $this->actingAs($mgr)->withSession(['company_id' => $company->id])
            ->get(route('warehouse.index'))->assertOk();

        $this->actingAs($mgr)->withSession(['company_id' => $company->id])
            ->post(route('warehouse.receipt'), ['name' => 'МДФ', 'quantity' => 10])
            ->assertForbidden();
    }

    public function test_employee_has_no_warehouse_access(): void
    {
        $emp = $this->user('employee');

        $this->actingAs($emp)->get(route('warehouse.index'))->assertForbidden();
    }

    public function test_warehouse_scoped_by_current_company(): void
    {
        $baia = Company::where('code', 'BAIA')->firstOrFail();
        $asu = Company::where('code', 'ASU')->firstOrFail();
        Material::create(['company_id' => $baia->id, 'name' => 'ЛДСП BAIA', 'unit' => 'штук', 'quantity' => 5]);
        Material::create(['company_id' => $asu->id, 'name' => 'МДФ ASU', 'unit' => 'штук', 'quantity' => 7]);

        $fin = $this->user('financist', $baia);

        $this->actingAs($fin)->withSession(['company_id' => $baia->id])
            ->get(route('warehouse.index'))
            ->assertInertia(fn ($p) => $p->component('Warehouse/Index')->has('materials', 1)
                ->where('materials.0.name', 'ЛДСП BAIA'));
    }
}
