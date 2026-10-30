<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // rates
        Schema::create('rates', function (Blueprint $table) {
            $table->id();
            $table->double('amount', 8, 2);
            $table->integer('status')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable();
        });

        // projects
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191);
            $table->string('description', 191);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // locations
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('address', 191);
            $table->integer('status')->default(1)->nullable();
            $table->unsignedBigInteger('rate_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable();
        });

        // rooms
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->unsignedBigInteger('location_id');
            $table->integer('rate_id');
            $table->integer('is_bedspace')->default(1);
            $table->integer('status')->default(1)->nullable();
            $table->integer('ordered');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable();
        });

        // beds
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->integer('status');
            $table->integer('sort')->nullable();
            $table->unsignedBigInteger('room_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable();
        });

        // users
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('email', 191)->unique();
            $table->string('password', 191)->nullable();
            $table->integer('location_id')->nullable();
            $table->integer('privilege')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // password_resets
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email', 191)->index();
            $table->string('token', 191);
            $table->timestamp('created_at')->nullable();
        });

        // customers
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->integer('location_id')->nullable();
            $table->string('first_name', 191);
            $table->string('last_name', 191);
            $table->string('middle_name', 191)->nullable();
            $table->string('sirb_no', 191);
            $table->string('mobile_no', 191);
            $table->string('permanent_address', 191);
            $table->string('rank', 191);
            $table->string('agency', 191);
            $table->string('icoe_name', 191);
            $table->string('icoe_relation', 191);
            $table->string('icoe_contact', 191);
            $table->string('note', 191);
            $table->integer('status')->default(0)->nullable();
            $table->integer('balance')->default(0)->nullable();
            $table->float('penalty_amount')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->integer('user_id')->nullable();
        });

        // customer_walkin
        Schema::create('customer_walkin', function (Blueprint $table) {
            $table->id();
            $table->string('name', 250);
            $table->string('id_presented', 250);
            $table->integer('status')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->integer('user_id');
        });

        // custom_rates
        Schema::create('custom_rates', function (Blueprint $table) {
            $table->id();
            $table->integer('rate_id');
            $table->integer('hours');
            $table->integer('status');
            $table->integer('type');
            $table->integer('location_id');
            $table->float('rate_per_hour');
        });

        // files
        Schema::create('files', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id');
            $table->string('filename', 200)->nullable();
            $table->integer('user_id')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('created_at')->nullable();
            $table->string('title', 100)->nullable();
        });

        // histories
        Schema::create('histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('type', 191);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // transactions
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('unique_id', 100)->nullable();
            $table->unsignedBigInteger('bed_id');
            $table->unsignedBigInteger('customer_id');
            $table->integer('location_id')->nullable();
            $table->integer('no_day');
            $table->integer('extend_day')->nullable();
            $table->integer('remaining_day')->nullable();
            $table->integer('consumed_day')->nullable();
            $table->integer('penalty_day')->nullable();
            $table->float('rates')->nullable();
            $table->double('amount', 8, 2);
            $table->string('status', 191);
            $table->integer('isContinue')->default(0)->nullable();
            $table->double('penalty')->nullable();
            $table->float('final_amount')->nullable();
            $table->dateTime('login');
            $table->dateTime('logout')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // transaction_details
        Schema::create('transaction_details', function (Blueprint $table) {
            $table->id();
            $table->integer('transaction_id');
            $table->integer('location_id');
            $table->integer('status');
            $table->integer('extend_days')->nullable();
            $table->integer('penalty_days')->nullable();
            $table->timestamp('extend_date')->nullable();
            $table->float('amount')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // transaction_walkin
        Schema::create('transaction_walkin', function (Blueprint $table) {
            $table->id();
            $table->string('unique_id', 250)->nullable();
            $table->integer('location_id');
            $table->integer('customer_id');
            $table->integer('room_id');
            $table->integer('bed_id');
            $table->integer('transaction_type');
            $table->integer('hours');
            $table->integer('extend_hours')->nullable();
            $table->float('rates');
            $table->dateTime('login');
            $table->dateTime('time_out')->nullable();
            $table->dateTime('logout')->nullable();
            $table->integer('status');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->integer('user_id');
        });

        // transaction_walkin_details
        Schema::create('transaction_walkin_details', function (Blueprint $table) {
            $table->id();
            $table->integer('transaction_id');
            $table->integer('location_id');
            $table->integer('status');
            $table->integer('hours');
            $table->float('amount');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        // bed_history
        Schema::create('bed_history', function (Blueprint $table) {
            $table->id();
            $table->integer('transaction_id');
            $table->integer('transaction_detail_id')->nullable();
            $table->integer('customer_id');
            $table->integer('bed_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // balance
        Schema::create('balance', function (Blueprint $table) {
            $table->increments('balance_id');
            $table->integer('customer_id');
            $table->integer('location_id');
            $table->integer('room_id');
            $table->integer('balance');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // penalties
        Schema::create('penalties', function (Blueprint $table) {
            $table->increments('penalty_id');
            $table->integer('transaction_id');
            $table->integer('customer_id');
            $table->double('amount');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->integer('user_id')->nullable();
        });

        // switch_history
        Schema::create('switch_history', function (Blueprint $table) {
            $table->id();
            $table->integer('transaction_id');
            $table->integer('day_remain');
            $table->float('rate');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_history');
        Schema::dropIfExists('penalties');
        Schema::dropIfExists('balance');
        Schema::dropIfExists('bed_history');
        Schema::dropIfExists('transaction_walkin_details');
        Schema::dropIfExists('transaction_walkin');
        Schema::dropIfExists('transaction_details');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('histories');
        Schema::dropIfExists('files');
        Schema::dropIfExists('custom_rates');
        Schema::dropIfExists('customer_walkin');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('users');
        Schema::dropIfExists('beds');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('rates');
    }
};
