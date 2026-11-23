<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seaman_loan_applications')) {
            return;
        }

        Schema::create('seaman_loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('address', 500);
            $table->date('birthday');
            $table->string('phone', 50);
            $table->string('email');
            $table->string('position_rank');
            $table->decimal('basic_salary', 12, 2);
            $table->date('departure_date');
            $table->string('agency_name');
            $table->decimal('loanable_amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seaman_loan_applications');
    }
};
