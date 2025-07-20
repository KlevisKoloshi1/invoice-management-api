<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'item_unit_id')) {
                $table->unsignedBigInteger('item_unit_id')->nullable();
            }
            if (!Schema::hasColumn('invoice_items', 'tax_rate_id')) {
                $table->unsignedBigInteger('tax_rate_id')->nullable();
            }
            if (!Schema::hasColumn('invoice_items', 'item_type_id')) {
                $table->unsignedBigInteger('item_type_id')->nullable();
            }
            if (!Schema::hasColumn('invoice_items', 'item_code')) {
                $table->string('item_code', 50)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'item_unit_id')) {
                $table->dropColumn('item_unit_id');
            }
            if (Schema::hasColumn('invoice_items', 'tax_rate_id')) {
                $table->dropColumn('tax_rate_id');
            }
            if (Schema::hasColumn('invoice_items', 'item_type_id')) {
                $table->dropColumn('item_type_id');
            }
            if (Schema::hasColumn('invoice_items', 'item_code')) {
                $table->dropColumn('item_code');
            }
        });
    }
};
