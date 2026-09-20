<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Deduplicate any existing duplicate claims before creating unique constraint
        $duplicates = DB::table('promotion_claims')
            ->select('promotion_id', 'user_id', DB::raw('MAX(id) as keep_id'))
            ->groupBy('promotion_id', 'user_id')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        foreach ($duplicates as $dupe) {
            DB::table('promotion_claims')
                ->where('promotion_id', $dupe->promotion_id)
                ->where('user_id', $dupe->user_id)
                ->where('id', '!=', $dupe->keep_id)
                ->delete();
        }

        // 2. Add unique constraint to promotion_claims
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->unique(['promotion_id', 'user_id'], 'prom_claim_user_unique');
        });

        // 3. Add lifecycle & audit columns to promotion_redemptions
        Schema::table('promotion_redemptions', function (Blueprint $table) {
            if (!Schema::hasColumn('promotion_redemptions', 'status')) {
                $table->enum('status', ['completed', 'reversed', 'voided'])->default('completed')->after('order_total');
            }
            if (!Schema::hasColumn('promotion_redemptions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('promotion_redemptions', 'reversal_reason')) {
                $table->string('reversal_reason')->nullable()->after('reversed_at');
            }
            $table->index(['promotion_id', 'status'], 'prom_redemption_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_claims', function (Blueprint $table) {
            $table->dropUnique('prom_claim_user_unique');
        });

        Schema::table('promotion_redemptions', function (Blueprint $table) {
            $table->dropIndex('prom_redemption_status_idx');
            $table->dropColumn(['status', 'reversed_at', 'reversal_reason']);
        });
    }
};
