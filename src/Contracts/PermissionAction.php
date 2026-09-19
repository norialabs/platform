<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Contracts;

/**
 * A verb the product grants. Implemented by a backed enum in the host, which
 * is where $value comes from.
 *
 * Extending BackedEnum rather than declaring `public string $value { get; }`:
 * the property form is an interface property, which is PHP 8.4, and this
 * package runs on 8.3. PHP forbids a userland class implementing BackedEnum,
 * so this says the same thing the docblock always said - implementers are
 * backed enums - and says it to the compiler.
 */
interface PermissionAction extends \BackedEnum
{
    public function label(): string;
}
