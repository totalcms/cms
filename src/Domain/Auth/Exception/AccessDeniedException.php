<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Exception;

/**
 * Thrown below the HTTP layer when the current actor may not perform a
 * write: the storage-level check in ObjectSaver, ObjectUpdater and
 * ObjectRemover, behind every entry point's own check. Reaching it means an
 * entry point let a write through that it should have refused.
 * DefaultErrorHandler answers it with 403.
 */
final class AccessDeniedException extends \RuntimeException
{
}
