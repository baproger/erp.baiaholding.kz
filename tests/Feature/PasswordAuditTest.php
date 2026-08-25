<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Смена пароля сотрудника обязана оставлять след в Аудите (кто и когда),
 * но БЕЗ значений: хэши маскируются как «•••».
 */
class PasswordAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_is_audited_masked(): void
    {
        $user = User::factory()->create();
        $user->update(['password' => Hash::make('новый-пароль-123')]);

        $log = AuditLog::where('table_name', 'users')
            ->where('record_id', $user->id)
            ->where('field_name', 'password')->first();

        $this->assertNotNull($log, 'Факт смены пароля должен попасть в аудит');
        $this->assertSame('•••', $log->old_value);
        $this->assertSame('•••', $log->new_value);
        $this->assertStringNotContainsString('$2y$', (string) $log->new_value);
    }
}
