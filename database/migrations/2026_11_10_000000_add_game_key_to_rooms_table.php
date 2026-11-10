<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which game the room plays (a key of config('games.available')). Null while the host is
     * still on the picker. Rooms from before the picker were all Bomb Busters.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('game_key', 32)->nullable()->after('capacity');
        });

        DB::table('rooms')->update(['game_key' => 'bombbusters']);
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('game_key');
        });
    }
};
