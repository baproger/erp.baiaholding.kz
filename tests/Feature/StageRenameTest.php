<?php

namespace Tests\Feature;

use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_renamed_stage_reflected_via_translated_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $stage = DealStage::where('name', 'Договор')->first();

        $this->actingAs($admin)->put(route('stages.update', ['deal', $stage->id]), ['name' => 'Контракт'])->assertRedirect();

        $fresh = DealStage::with('translations')->find($stage->id);
        $this->assertEquals('Контракт', $fresh->name);
        $this->assertEquals('Контракт', $fresh->translatedName('ru'));
    }

    public function test_new_stage_visible_via_translated_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('stages.store'), ['kind' => 'deal', 'name' => 'Аванс'])->assertRedirect();

        $stage = DealStage::with('translations')->where('name', 'Аванс')->first();
        $this->assertEquals('Аванс', $stage->translatedName('ru'));
    }
}
