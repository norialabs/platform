<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Platform\Platform;

class Tenancy
{
    private ?string $workspaceId = null;

    /** @var array<string, string> the settings this connection has been told to hold */
    private array $gucs = [];

    public function id(): ?string
    {
        return $this->workspaceId;
    }

    public function idOrFail(): string
    {
        return $this->workspaceId ?? throw new TenancyMissing('No workspace is set on this request.');
    }

    public function set(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        $this->pushGuc($this->workspaceGuc(), $workspaceId);
    }

    public function clear(): void
    {
        $this->workspaceId = null;

        $this->pushGucs(array_fill_keys($this->declared(), ''));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $workspaceId, Closure $callback): mixed
    {
        $previous = $this->workspaceId;
        $this->set($workspaceId);

        try {
            return $callback();
        } finally {
            $this->restore(fn () => $previous === null ? $this->clearWorkspace() : $this->set($previous));
        }
    }

    /**
     * @template TReturn
     *
     * @param  array<string, string>  $settings
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withGuc(array $settings, Closure $callback): mixed
    {
        $previous = [];

        foreach ($settings as $name => $value) {
            $previous[$name] = $this->gucs[$name] ?? '';
            $this->pushGuc($name, $value);
        }

        try {
            return $callback();
        } finally {
            $this->restore(function () use ($previous): void {
                foreach ($previous as $name => $value) {
                    $this->pushGuc($name, $value);
                }
            });
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asStaff(Closure $callback): mixed
    {
        return $this->withGuc([Config::string('noria.tenancy.staff_read_guc', 'app.staff_read') => 'on'], $callback);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function asPlatform(Closure $callback): mixed
    {
        return $this->withGuc([Config::string('noria.tenancy.noria_write_guc', 'app.noria_write') => 'on'], $callback);
    }

    private function restore(Closure $reset): void
    {
        try {
            $reset();
        } catch (QueryException) {
            $this->gucs = [];

            if ($this->connection()->transactionLevel() === 0) {
                DB::disconnect(Platform::connection());
            }
        }
    }

    private function clearWorkspace(): void
    {
        $this->workspaceId = null;
        $this->pushGuc($this->workspaceGuc(), '');
    }

    private function pushGuc(string $name, string $value): void
    {
        $this->pushGucs([$name => $value]);
    }

    /**
     * @param  array<string, string>  $settings
     */
    private function pushGucs(array $settings): void
    {
        if ($settings === [] || ! Config::boolean('noria.tenancy.enabled', true)) {
            return;
        }

        $declared = $this->declared();
        $bindings = [];

        foreach ($settings as $name => $value) {
            if (! in_array($name, $declared, true)) {
                throw new TenancyMissing("{$name} is not a setting this application clears.");
            }

            array_push($bindings, $name, $value);
        }

        $this->connection()->statement(
            'select '.implode(', ', array_fill(0, count($settings), 'set_config(?, ?, false)')),
            $bindings,
        );

        foreach ($settings as $name => $value) {
            $this->gucs[$name] = $value;
        }
    }

    /** @return list<string> */
    private function declared(): array
    {
        $gucs = Config::array('noria.tenancy.gucs', []);
        $names = array_values(array_filter($gucs, is_string(...)));

        return in_array($this->workspaceGuc(), $names, true) ? $names : [$this->workspaceGuc(), ...$names];
    }

    private function workspaceGuc(): string
    {
        return Config::string('noria.tenancy.workspace_guc', 'app.workspace_id');
    }

    private function connection(): Connection
    {
        return DB::connection(Platform::connection());
    }
}
