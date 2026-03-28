<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aviagram_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('callback_url')->nullable();
            $table->string('status')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_message')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->json('provider_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->boolean('forwarded')->default(false);
            $table->unsignedSmallInteger('forward_status')->nullable();
            $table->text('forward_error')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviagram_transactions');
    }
};
