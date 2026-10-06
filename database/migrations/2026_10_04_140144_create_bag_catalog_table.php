<?php

declare(strict_types=1);

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bag_catalog', function (Blueprint $table) {
            $table->string('catalog_id')->primary();
            $table->string('kind')->default(BagKindEnum::GEM->value);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('in_shop')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('price')->default(0);
            $table->string('currency')->default(CurrencyEnum::SILVER->value);
            $table->string('profile')->nullable();
            $table->unsignedInteger('effect_value')->nullable();
            $table->string('type')->nullable();
            $table->unsignedInteger('max_durability')->nullable();
            $table->unsignedInteger('mf_dodge')->default(0);
            $table->unsignedInteger('mf_anti_dodge')->default(0);
            $table->unsignedInteger('mf_crit')->default(0);
            $table->unsignedInteger('mf_anti_crit')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['kind', 'enabled', 'in_shop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bag_catalog');
    }
};
