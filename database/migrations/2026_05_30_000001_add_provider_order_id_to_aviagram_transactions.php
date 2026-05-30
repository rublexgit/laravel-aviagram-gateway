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
            // The order ID the provider generates and returns in the createForm
            // response. This — not our internal order_id — is the value the
            // provider echoes back in its callback, so callback verification
            // must match against this column.
            $table->string('provider_order_id')->nullable()->after('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('aviagram_transactions', function (Blueprint $table): void {
            $table->dropColumn('provider_order_id');
        });
    }
};
