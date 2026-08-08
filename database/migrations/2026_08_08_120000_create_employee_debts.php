<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Долг сотрудника перед компанией: выдали деньги — дальше система сама, каждый
 * месяц, гасит его ФИКСИРОВАННЫМ платежом и ТОЛЬКО из бонуса. Оклад не
 * трогается никогда: нет бонуса в этом месяце — ничего не удержано, остаток
 * переезжает на следующий.
 *
 * Гашения хранятся помесячно (а не считаются на лету): бонус прошлого месяца
 * может измениться задним числом (доплатили по сделке), и без записей история
 * погашений молча переписывалась бы. Уникальный (долг, месяц) делает начисление
 * идемпотентным — команду можно гонять повторно без двойного списания.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);              // выдано
            $table->decimal('monthly_amount', 14, 2);      // фиксированный платёж в месяц
            $table->date('date');                          // дата выдачи
            $table->string('payment_method', 10)->nullable(); // cash | bank
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();    // погашен полностью
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
        });

        Schema::create('employee_debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_debt_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);                      // YYYY-MM
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['employee_debt_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_debt_payments');
        Schema::dropIfExists('employee_debts');
    }
};
