<?php

declare(strict_types=1);

/*
 * Functions for templates. Composer loads this file on every request ("files" in composer.json).
 */

/** Escapes text for HTML. Every value printed into a template goes through this. */
function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
