<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Exception;

/**
 * Every render slot stayed busy for the whole wait: the public routes answer
 * with a 503 and a Retry-After instead of queueing one more render.
 */
final class RenderBusyException extends \RuntimeException
{
	public function __construct(public readonly int $retryAfter)
	{
		parent::__construct('ImageWorks is busy rendering other images');
	}
}
