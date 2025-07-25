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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->string('description')->nullable();
            $table->string('item_name', 100);
            $table->decimal('quantity', 12, 2);
            $table->decimal('price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('unit')->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('item_vat_rate', 5, 2)->nullable();
            $table->decimal('item_total_before_vat', 12, 2)->nullable();
            $table->decimal('item_vat_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('item_unit_id')->nullable();
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->unsignedBigInteger('item_type_id')->nullable();
            $table->string('item_code', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
