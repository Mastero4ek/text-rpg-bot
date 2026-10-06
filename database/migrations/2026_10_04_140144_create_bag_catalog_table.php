<?php

declare(strict_types=1);

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gems', function (Blueprint $table) {
            $table->string('gem_id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default(GemTypeEnum::RUBY->value);
            $table->boolean('in_shop')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('price')->default(0);
            $table->string('currency')->default(CurrencyEnum::SILVER->value);
            $table->unsignedInteger('max_durability')->default(0);
            $table->unsignedInteger('mf_dodge')->default(0);
            $table->unsignedInteger('mf_anti_dodge')->default(0);
            $table->unsignedInteger('mf_crit')->default(0);
            $table->unsignedInteger('mf_anti_crit')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['type', 'enabled', 'in_shop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gems');
    }
};
