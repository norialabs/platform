<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use NoriaLabs\Platform\Db\Timestamps;
use NoriaLabs\Platform\Platform;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Platform::connection();
    }

    private function tz(): bool
    {
        return Timestamps::aware();
    }

    private function stamps(Blueprint $table): void
    {
        $this->tz() ? $table->timestampsTz() : $table->timestamps();
    }

    private function moment(Blueprint $table, string $name): ColumnDefinition
    {
        return $this->tz() ? $table->timestampTz($name) : $table->timestamp($name);
    }

    public function up(): void
    {
        Timestamps::assertAligned();

        $tenantColumn = Config::string('noria.tenancy.column', 'workspace_id');

        Schema::create(Platform::table('audit_logs'), function (Blueprint $table) use ($tenantColumn): void {
            $table->uuid('id')->primary();
            $table->uuid($tenantColumn)->nullable()->index();

            $table->string('actor_id', 64)->nullable()->index();
            $table->string('actor_type', 32)->default('user');
            $table->string('action', 128)->index();
            $table->string('target_type', 256)->nullable();
            $table->string('target_id', 128)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable()->index();
            $this->moment($table, 'created_at')->nullable();

            $table->index(['target_type', 'target_id']);
            $table->index([$tenantColumn, 'created_at']);
        });

        Schema::create(Platform::table('otp_challenges'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('destination_hash', 64)->index();
            $table->string('destination_hint', 128)->nullable();
            $table->string('channel', 16)->default('email');
            $table->string('code_hash', 256);
            $table->unsignedSmallInteger('attempts')->default(0);
            $this->moment($table, 'expires_at')->index();
            $this->moment($table, 'consumed_at')->nullable();
            $this->stamps($table);
        });

        Schema::create(Platform::table('invitations'), function (Blueprint $table) use ($tenantColumn): void {
            $table->uuid('id')->primary();
            $table->uuid($tenantColumn)->nullable()->index();
            $table->string('destination_hash', 64)->index();
            $table->string('destination_hint', 128)->nullable();
            $table->string('channel', 16)->default('email');
            $table->string('role', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('invited_by', 64)->nullable();
            $table->string('accepted_by', 64)->nullable();
            $this->moment($table, 'expires_at')->index();
            $this->moment($table, 'accepted_at')->nullable();
            $this->moment($table, 'revoked_at')->nullable();
            $this->stamps($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Platform::table('invitations'));
        Schema::dropIfExists(Platform::table('otp_challenges'));
        Schema::dropIfExists(Platform::table('audit_logs'));
    }
};
