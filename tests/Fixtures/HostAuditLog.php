<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Tests\Fixtures;

use NoriaLabs\Platform\Audit\AuditLog;

class HostAuditLog extends AuditLog
{
    public function label(): string
    {
        return 'host';
    }
}
