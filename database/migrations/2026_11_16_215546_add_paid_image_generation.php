<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('paid_balance_pico')->default(0);
            $table->bigInteger('test_balance_pico')->default(0);
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->string('provider')->default('pixelforge')->index();
            $table->string('wallet')->default('demo');
            $table->date('budget_date')->nullable();
        });
        Schema::create('credit_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('price_id');
            $table->boolean('livemode');
            $table->string('status')->default('pending');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('payment_intent_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamps();
        });
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('payload_hash', 64);
            $table->timestamp('processed_at');
        });
        Schema::create('generated_assets', function (Blueprint $table) {
            $table->foreignUuid('generation_id')->primary()->constrained('generations')->cascadeOnDelete();
            $table->longText('data_base64');
            $table->string('sha256', 64);
            $table->timestamps();
        });
        Schema::create('image_budgets', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->bigInteger('spent_pico')->default(0);
            $table->bigInteger('reserved_pico')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_budgets');
        Schema::dropIfExists('generated_assets');
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('credit_orders');
        Schema::table('generations', fn (Blueprint $table) => $table->dropColumn(['provider', 'wallet', 'budget_date']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['paid_balance_pico', 'test_balance_pico']));
    }
};
