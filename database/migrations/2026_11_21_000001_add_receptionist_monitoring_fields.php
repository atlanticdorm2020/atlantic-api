<?php

use App\Models\Location;
use App\Support\BranchColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('locations') && ! Schema::hasColumn('locations', 'color')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->string('color', 7)->nullable()->after('address');
            });
        }

        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'checked_in_by')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('checked_in_by')->nullable()->after('location_id');
                $table->index('checked_in_by');
                $table->index(['location_id', 'login']);
                $table->index(['checked_in_by', 'login']);
            });
        }

        if (! Schema::hasTable('receptionist_duties')) {
            Schema::create('receptionist_duties', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('location_id');
                $table->date('duty_date');
                $table->time('start_time');
                $table->time('cutoff_time');
                $table->boolean('overnight')->default(false);
                $table->string('timezone', 64)->default('Asia/Manila');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'duty_date']);
                $table->index(['location_id', 'duty_date']);
            });
        }

        if (! Schema::hasTable('receptionist_duty_histories')) {
            Schema::create('receptionist_duty_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('duty_id');
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->json('changes')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        Location::query()
            ->where(function ($query) {
                $query->whereNull('color')->orWhere('color', '');
            })
            ->get()
            ->each(function (Location $location) {
                $location->forceFill([
                    'color' => BranchColor::fallback($location->name, $location->id),
                ])->save();
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('receptionist_duty_histories');
        Schema::dropIfExists('receptionist_duties');

        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'checked_in_by')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropIndex(['checked_in_by']);
                $table->dropIndex(['location_id', 'login']);
                $table->dropIndex(['checked_in_by', 'login']);
                $table->dropColumn('checked_in_by');
            });
        }

        if (Schema::hasTable('locations') && Schema::hasColumn('locations', 'color')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};
