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
            $table->decimal('quantity', 12, 2)->change();
            if (!Schema::hasColumn('invoice_items', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('item_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->integer('quantity')->change();
            if (Schema::hasColumn('invoice_items', 'warehouse_id')) {
                $table->dropColumn('warehouse_id');
            }
        });
    }
}; 