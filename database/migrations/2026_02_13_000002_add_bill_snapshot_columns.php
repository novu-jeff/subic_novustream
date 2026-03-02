<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill', function (Blueprint $table) {
            $table->string('bill_owner_name')->nullable()->after('payor_name');
            $table->string('bill_account_no')->nullable()->after('bill_owner_name');
            $table->string('bill_address')->nullable()->after('bill_account_no');
            $table->string('bill_meter_serial_no')->nullable()->after('bill_address');
        });
    }

    public function down(): void
    {
        Schema::table('bill', function (Blueprint $table) {
            $table->dropColumn([
                'bill_owner_name',
                'bill_account_no',
                'bill_address',
                'bill_meter_serial_no',
            ]);
        });
    }
};

