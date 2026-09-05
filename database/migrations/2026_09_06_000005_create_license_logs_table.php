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
        Schema::create('license_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->nullable()->constrained('applications')->onDelete('set null');
            $table->foreignId('license_id')->nullable()->constrained('licenses')->onDelete('set null');
            $table->string('event', 50);
            $table->string('actor_type', 20)->index();
            $table->foreignId('actor_user_id')->nullable()->index()->constrained('users')->onDelete('set null');
            $table->string('actor_key_id', 50)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['license_id', 'created_at']);
            $table->index(['application_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_logs');
    }
};
