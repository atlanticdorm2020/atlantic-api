<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seaman_loan_payments')) {
            return;
        }

        Schema::create('seaman_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seaman_loan_application_id');
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->date('paid_at');
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index('seaman_loan_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seaman_loan_payments');
    }
};
