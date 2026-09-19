<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Audit\AuditLog;

/** What a host would write to hang its own relations off the trail. */
class HostAuditLog extends AuditLog
{
    public function label(): string
    {
        return 'host';
    }
}
