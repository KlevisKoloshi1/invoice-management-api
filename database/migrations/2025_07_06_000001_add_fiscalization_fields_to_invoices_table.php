<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        $columns = [
            ["fiscalization_status", "VARCHAR(255)"],
            ["fiscalization_url", "VARCHAR(255)"],
            ["fiscalized", "BOOLEAN DEFAULT FALSE"],
            ["fiscalization_response", "TEXT"],
            ["fiscalized_at", "TIMESTAMP NULL"],
            ["iic", "VARCHAR(255)"],
            ["fic", "VARCHAR(255)"],
            ["tin", "VARCHAR(255)"],
            ["crtd", "VARCHAR(255)"],
            ["ord", "VARCHAR(255)"],
            ["bu", "VARCHAR(255)"],
            ["cr", "VARCHAR(255)"],
            ["sw", "VARCHAR(255)"],
            ["prc", "NUMERIC(15,2)"],
        ];
        foreach ($columns as [$name, $type]) {
            DB::statement("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS $name $type");
        }
    }

    public function down()
    {
        $columns = [
            "fiscalization_status", "fiscalization_url", "fiscalized", "fiscalization_response", "fiscalized_at",
            "iic", "fic", "tin", "crtd", "ord", "bu", "cr", "sw", "prc"
        ];
        foreach ($columns as $name) {
            DB::statement("ALTER TABLE invoices DROP COLUMN IF EXISTS $name");
        }
    }
}; 