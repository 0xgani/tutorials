<?php

declare(strict_types=1);

namespace Bookshelf\Container;

use LogicException;

/** The container cannot create a service: a wiring mistake, so a bug to fix rather than an error to handle. */
final class ContainerException extends LogicException
{
}
