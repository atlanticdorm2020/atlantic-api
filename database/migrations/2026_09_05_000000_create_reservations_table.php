<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('location_id');
            $table->string('type', 20);
            $table->unsignedInteger('room_id')->nullable();
            $table->unsignedInteger('custom_rate_id')->nullable();
            $table->unsignedTinyInteger('transaction_type')->nullable();
            $table->unsignedInteger('no_day')->nullable();
            $table->unsignedInteger('hours')->nullable();
            $table->decimal('estimated_amount', 10, 2)->nullable();
            $table->dateTime('preferred_check_in');
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['location_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
