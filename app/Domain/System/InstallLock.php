<?php

namespace App\Domain\System;

use Illuminate\Support\Facades\DB;

/**
 * Session-level PostgreSQL advisory lock held on a dedicated connection for the
 * whole install/upgrade command (no wrapping transaction around DDL).
 */
class InstallLock
{
    private const CONNECTION = 'system_install_lock';

    private bool $held = false;

    public function acquire(): bool
    {
        $default = config('database.default');
        config(['database.connections.'.self::CONNECTION => config("database.connections.{$default}")]);
        DB::purge(self::CONNECTION);

        $row = DB::connection(self::CONNECTION)
            ->selectOne('SELECT pg_try_advisory_lock(?) AS locked', [config('kitchen.install.lock_key')]);

        return $this->held = (bool) $row->locked;
    }

    public function release(): void
    {
        if ($this->held) {
            DB::connection(self::CONNECTION)->select('SELECT pg_advisory_unlock(?)', [config('kitchen.install.lock_key')]);
            $this->held = false;
        }

        DB::disconnect(self::CONNECTION);
    }
}
