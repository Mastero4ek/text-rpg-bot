<?php

declare(strict_types=1);

use App\Enums\Appeal\AppealStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id');
            $table->text('body');
            $table->string('status')->default(AppealStatusEnum::New->value);
            $table->timestamps();

            $table->foreign('tg_id')->references('tg_id')->on('characters');
            $table->index(['tg_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
