<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('address_name')->nullable()->after('type');
        });

        // Backfill existing addresses with unique names per user
        $addresses = DB::table('addresses')->orderBy('user_id')->orderBy('id')->get();
        $userCounts = [];

        foreach ($addresses as $addr) {
            $userCounts[$addr->user_id] = ($userCounts[$addr->user_id] ?? 0) + 1;
            $count = $userCounts[$addr->user_id];
            $defaultName = $count === 1 ? 'Home' : ($count === 2 ? 'Office' : "Address {$count}");
            
            DB::table('addresses')
                ->where('id', $addr->id)
                ->update(['address_name' => $defaultName]);
        }
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('address_name');
        });
    }
};
