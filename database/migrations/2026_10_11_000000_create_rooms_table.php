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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('capacity');
            $table->string('status', 16)->default('lobby')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('room_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->boolean('is_ready')->default(false);
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
            $table->unique(['room_id', 'seat']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_players');
        Schema::dropIfExists('rooms');
    }
};
