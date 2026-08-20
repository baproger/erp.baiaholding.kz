<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Источник (портал) лота — тот же справочник, что у сделок (Deal::SOURCES). */
    public function up(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->string('source', 40)->nullable()->after('product');
        });
    }

    public function down(): void
    {
        Schema::table('pre_deals', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
