<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('customer_id', 32)->nullable()->unique()->after('id');
        });

        // Backfill existing customer records
        $customers = DB::table('users')
            ->where('role', 'customer')
            ->orderBy('id', 'asc')
            ->get();

        $registeredCount = 10001;
        $guestCount = 10001;

        foreach ($customers as $c) {
            if ($c->customer_type === 'guest') {
                $customerId = sprintf('GUEST-%05d', $guestCount++);
            } else {
                $customerId = sprintf('CUST-%05d', $registeredCount++);
            }

            DB::table('users')
                ->where('id', $c->id)
                ->update(['customer_id' => $customerId]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
