<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('receptionist_duties') || Schema::hasColumn('receptionist_duties', 'end_date')) {
            return;
        }

        Schema::table('receptionist_duties', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('duty_date');
            $table->index(['duty_date', 'end_date']);
        });

        DB::statement('UPDATE receptionist_duties SET end_date = duty_date WHERE end_date IS NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('receptionist_duties') || ! Schema::hasColumn('receptionist_duties', 'end_date')) {
            return;
        }

        Schema::table('receptionist_duties', function (Blueprint $table) {
            $table->dropIndex(['duty_date', 'end_date']);
            $table->dropColumn('end_date');
        });
    }
};
