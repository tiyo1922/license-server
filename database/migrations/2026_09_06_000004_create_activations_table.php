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
        Schema::create('activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->unique()->constrained('licenses')->onDelete('cascade');
            $table->string('canonical_domain', 255)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('token_id', 50)->unique();
            $table->timestamp('token_expires_at')->index();
            $table->timestamp('activated_at');
            $table->timestamp('last_verified_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activations');
    }
};
