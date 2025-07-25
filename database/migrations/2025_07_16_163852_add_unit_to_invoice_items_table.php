<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'unit' column only if it does not exist
        if (!Schema::hasColumn('invoice_items', 'unit')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('unit')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('invoice_items', 'unit')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }
};
