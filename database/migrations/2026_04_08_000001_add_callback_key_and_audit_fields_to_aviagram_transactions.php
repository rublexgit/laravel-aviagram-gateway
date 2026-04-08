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
            // Callback key security — store only the hash, never the raw token
            $table->string('callback_key_hash')->nullable()->unique()->after('callback_url');
            $table->boolean('callback_key_consumed')->default(false)->after('callback_key_hash');

            // Expected values captured at payment-init time for callback validation
            $table->string('expected_amount')->nullable()->after('callback_key_consumed');
            $table->string('expected_currency', 10)->nullable()->after('expected_amount');

            // Audit: raw inbound callback request
            $table->text('callback_request_url')->nullable()->after('callback_payload');
            $table->string('callback_client_ip', 45)->nullable()->after('callback_request_url');
            $table->json('callback_headers')->nullable()->after('callback_client_ip');
            $table->text('callback_raw_body')->nullable()->after('callback_headers');

            // Validation outcome
            $table->boolean('callback_validation_passed')->nullable()->after('callback_raw_body');
            $table->text('callback_validation_reason')->nullable()->after('callback_validation_passed');

            // Async forward job tracking
            $table->boolean('forward_job_dispatched')->default(false)->after('forwarded_at');
            $table->timestamp('forward_job_dispatched_at')->nullable()->after('forward_job_dispatched');
        });
    }

    public function down(): void
    {
        Schema::table('aviagram_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'callback_key_hash',
                'callback_key_consumed',
                'expected_amount',
                'expected_currency',
                'callback_request_url',
                'callback_client_ip',
                'callback_headers',
                'callback_raw_body',
                'callback_validation_passed',
                'callback_validation_reason',
                'forward_job_dispatched',
                'forward_job_dispatched_at',
            ]);
        });
    }
};
