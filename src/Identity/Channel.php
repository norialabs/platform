<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Identity;

/** How a code or an invitation reaches somebody. */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';

    public function needsEmail(): bool
    {
        return $this === self::Email;
    }
}
