<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false));
        Schema::create('generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_request_id');
            $t->string('request_hash', 64);
            $t->text('prompt');
            $t->string('size');
            $t->string('quality');
            $t->json('reference_images');
            $t->string('status')->default('queued')->index();
            $t->string('provider_id')->nullable()->unique();
            $t->unsignedInteger('submit_attempts')->default(0);
            $t->boolean('submission_uncertain')->default(false);
            $t->boolean('needs_attention')->default(false);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('error_code')->nullable();
            $t->text('error_message')->nullable();
            $t->bigInteger('estimated_cost_pico');
            $t->bigInteger('minimum_cost_pico');
            $t->bigInteger('maximum_cost_pico');
            $t->json('rates')->nullable();
            $t->json('usage')->nullable();
            $t->text('image_url')->nullable();
            $t->timestamp('image_url_expires_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'client_request_id']);
        });
        Schema::create('charges', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('generation_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->bigInteger('provider_cost_pico');
            $t->bigInteger('user_charge_pico');
            $t->timestamp('completed_at')->index();
            $t->timestamps();
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('payload_hash', 64);
            $t->string('type');
            $t->json('payload');
            $t->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('charges');
        Schema::dropIfExists('generations');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
