<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('purpose');
            $table->string('otp_hash');
            $table->integer('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otps');
    }
};
