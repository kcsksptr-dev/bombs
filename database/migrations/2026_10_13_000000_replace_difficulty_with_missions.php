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
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('difficulty');
            $table->unsignedSmallInteger('mission')->default(1)->after('capacity');
        });

        // Campaign progress: which missions a player has helped win
        Schema::create('mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('mission');
            $table->timestamp('completed_at');

            $table->unique(['user_id', 'mission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mission_completions');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('mission');
            $table->string('difficulty', 16)->default('normal')->after('capacity');
        });
    }
};
