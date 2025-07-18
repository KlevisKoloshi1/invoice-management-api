<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('invoice_items', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
            if (Schema::hasColumn('invoice_items', 'vat_amount')) {
                $table->dropColumn('vat_amount');
            }
        });
    }
};
