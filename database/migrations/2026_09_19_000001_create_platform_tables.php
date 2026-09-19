<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Platform;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Platform::connection();
    }

    public function up(): void
    {
        /*
         * Both tables carry the tenant column but sit outside tenancy: the
         * trail outlives the workspace it describes, and a sign-in code is
         * read before anybody knows which workspace they are signing in to.
         * They are in platform.tenancy.unscoped_tables for the same reason.
         */
        Schema::create(Platform::table('audit_logs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();
            $table->uuid('actor_id')->nullable()->index();
            $table->string('actor_type', 32)->default('user');
            $table->string('action', 128)->index();
            $table->string('target_type', 256)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['target_type', 'target_id']);
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create(Platform::table('otp_challenges'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identifier', 256)->index();
            $table->string('code_hash', 256);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Platform::table('otp_challenges'));
        Schema::dropIfExists(Platform::table('audit_logs'));
    }
};
