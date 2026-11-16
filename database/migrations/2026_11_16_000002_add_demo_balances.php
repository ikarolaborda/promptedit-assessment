<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->bigInteger('balance_pico')->default(5000000000000));
        Schema::table('generations', fn (Blueprint $t) => $t->bigInteger('reserved_pico')->default(0));
        Schema::create('credit_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->uuid('request_id');
            $t->bigInteger('amount_pico');
            $t->timestamps();
            $t->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('balance_pico'));
        Schema::table('generations', fn (Blueprint $t) => $t->dropColumn('reserved_pico'));
    }
};
