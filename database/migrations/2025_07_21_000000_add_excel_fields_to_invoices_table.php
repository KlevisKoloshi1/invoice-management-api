<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('automatic_payment_method_id')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->unsignedBigInteger('cash_register_id')->nullable();
            $table->unsignedBigInteger('fiscal_invoice_type_id')->nullable();
            $table->unsignedBigInteger('fiscal_profile_id')->nullable();
        });
    }
    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'customer_id', 'city_id', 'automatic_payment_method_id', 'currency_id',
                'cash_register_id', 'fiscal_invoice_type_id', 'fiscal_profile_id'
            ]);
        });
    }
}; 