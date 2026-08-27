<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add abuse & risk management columns to users table
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'customer_type')) {
                $table->string('customer_type', 20)->default('registered')->after('role');
            }
            if (!Schema::hasColumn('users', 'risk_level')) {
                $table->string('risk_level', 20)->default('low')->after('status');
            }
            if (!Schema::hasColumn('users', 'risk_score')) {
                $table->integer('risk_score')->default(0)->after('risk_level');
            }
            if (!Schema::hasColumn('users', 'risk_reasons')) {
                $table->json('risk_reasons')->nullable()->after('risk_score');
            }
            if (!Schema::hasColumn('users', 'internal_notes')) {
                $table->text('internal_notes')->nullable()->after('risk_reasons');
            }
        });

        // 2. Create blocked_ips table
        if (!Schema::hasTable('blocked_ips')) {
            Schema::create('blocked_ips', function (Blueprint $table) {
                $table->id();
                $table->string('ip_address', 45)->unique()->index();
                $table->string('status', 20)->default('active')->index(); // active, expired, revoked
                $table->string('reason');
                $table->text('notes')->nullable();
                $table->foreignId('blocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at')->nullable()->index(); // null = permanent
                $table->timestamps();
            });
        }

        // 3. Create customer_ip_logs table for multi-IP tracking
        if (!Schema::hasTable('customer_ip_logs')) {
            Schema::create('customer_ip_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('ip_address', 45)->index();
                $table->string('action', 50)->default('order_created')->index();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('user_agent', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ip_logs');
        Schema::dropIfExists('blocked_ips');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'customer_type',
                'risk_level',
                'risk_score',
                'risk_reasons',
                'internal_notes',
            ]);
        });
    }
};
