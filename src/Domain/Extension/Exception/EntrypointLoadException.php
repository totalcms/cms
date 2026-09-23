<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Exception;

/**
 * An extension's entrypoint could not be turned into an ExtensionInterface
 * class. The message is what the extension's stored error records.
 */
final class EntrypointLoadException extends \RuntimeException
{
}
