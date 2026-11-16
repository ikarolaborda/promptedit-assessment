<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_provider_budgets', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->bigInteger('committed_pico')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_provider_budgets');
    }
};
