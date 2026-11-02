<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расходы «в долг» и кредиторка поставщикам (ТЗ 02.11.2026, п.3, 7).
 * Расход «в долг» сразу входит в затраты сделки, но кассу не трогает;
 * погашение — отдельная выплата из кассы (подтверждает бухгалтер с чеком).
 * Идемпотентно; date/dateTime, не timestamp NOT NULL (прод-MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'funding')) {
            // cash — оплачено деньгами (как раньше), debt — взято у поставщика в долг.
            Schema::table('expenses', fn (Blueprint $t) => $t->string('funding', 10)->default('cash')->index());
        }

        if (! Schema::hasTable('supplier_debts')) {
            Schema::create('supplier_debts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('deal_id')->nullable()->index();
                $t->unsignedBigInteger('expense_id')->nullable()->unique();
                $t->string('supplier_name');
                $t->string('supplier_contact', 120)->nullable(); // телефон / БИН
                $t->string('description')->nullable();
                $t->decimal('amount', 14, 2);
                $t->decimal('paid', 14, 2)->default(0);
                $t->date('due_date')->index();
                $t->string('document_path')->nullable();
                $t->string('status', 10)->default('active')->index(); // active | paid
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_debt_payments')) {
            Schema::create('supplier_debt_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('supplier_debt_id')->index();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->decimal('amount', 14, 2);
                $t->string('payment_method', 10); // cash | bank
                $t->date('date')->index();
                $t->string('receipt_path');
                $t->string('note')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debt_payments');
        Schema::dropIfExists('supplier_debts');
        if (Schema::hasColumn('expenses', 'funding')) {
            Schema::table('expenses', function (Blueprint $t) {
                $t->dropIndex(['funding']);
                $t->dropColumn('funding');
            });
        }
    }
};
