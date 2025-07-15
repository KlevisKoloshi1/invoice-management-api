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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->decimal('total', 12, 2);
            $table->string('status')->default('pending');
            $table->boolean('fiscalized')->default(false);
            $table->string('fiscalization_response')->nullable();
            $table->timestamp('fiscalized_at')->nullable();
            $table->string('iic')->nullable();
            $table->string('fic')->nullable();
            $table->string('tin')->nullable();
            $table->string('crtd')->nullable();
            $table->string('ord')->nullable();
            $table->string('bu')->nullable();
            $table->string('cr')->nullable();
            $table->string('sw')->nullable();
            $table->decimal('prc', 15, 2)->nullable();
            $table->string('fiscalization_status')->nullable();
            $table->string('fiscalization_url')->nullable();
            $table->string('number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('business_unit', 20)->nullable();
            $table->string('issuer_tin', 10)->nullable();
            $table->string('invoice_type', 20)->nullable();
            $table->boolean('is_e_invoice')->nullable();
            $table->string('operator_code', 20)->nullable();
            $table->string('software_code', 20)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->decimal('total_before_vat', 12, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('buyer_name', 100)->nullable();
            $table->string('buyer_address', 200)->nullable();
            $table->string('buyer_tax_number', 20)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
