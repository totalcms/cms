<?php

declare(strict_types=1);

namespace TotalCMS\Infrastructure\Filesystem;

/**
 * A filename that is not a single path segment — it holds a separator, is
 * `.`/`..`, or carries a NUL — reached a path builder. Extends
 * InvalidArgumentException so the error handler answers it with a 400.
 */
final class UnsafePathException extends \InvalidArgumentException
{
}
