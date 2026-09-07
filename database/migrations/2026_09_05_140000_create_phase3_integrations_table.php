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
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('name');
            $table->string('category')->index(); // payment, sms, email, courier, whatsapp, analytics, fraud
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_test_mode')->default(true);
            $table->longText('credentials')->nullable(); // json encoded credentials
            $table->longText('settings')->nullable(); // json encoded configuration parameters
            $table->timestamp('last_tested_at')->nullable();
            $table->string('test_status')->default('untested'); // connected, failed, untested
            $table->text('test_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
