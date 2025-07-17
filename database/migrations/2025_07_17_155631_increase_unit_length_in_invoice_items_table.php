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
        // Use raw SQL for PostgreSQL to ensure the column is altered
        DB::statement('ALTER TABLE invoice_items ALTER COLUMN unit TYPE VARCHAR(50)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE invoice_items ALTER COLUMN unit TYPE VARCHAR(10)');
    }
};
