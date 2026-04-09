<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aviagram_transactions', function (Blueprint $table): void {
            $table->text('forward_response_body')->nullable()->after('forward_error');
        });
    }

    public function down(): void
    {
        Schema::table('aviagram_transactions', function (Blueprint $table): void {
            $table->dropColumn('forward_response_body');
        });
    }
};
