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
        Schema::create('reading_dates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zone_id')->unique();

            $table->date('bill_period_from');
            $table->date('bill_period_to');
            $table->date('due_date');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('zone_id')
                ->references('id')
                ->on('zones')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reading_dates');
    }
};
