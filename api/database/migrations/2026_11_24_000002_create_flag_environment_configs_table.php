<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flag_environment_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flag_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 16);
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('percentage')->default(100);
            $table->json('allow_list');
            $table->timestamps();

            $table->unique(['flag_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flag_environment_configs');
    }
};
