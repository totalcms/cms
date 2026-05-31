<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Exception;

/**
 * Thrown when an admin/runtime attempt to write a builder template is refused
 * because templates are git-managed on this environment (see
 * BuilderTemplatePaths::locked() and docs/planning/builder-git-workflow.md).
 *
 * Mapped to HTTP 403 by DefaultErrorHandler.
 */
class TemplatesLockedException extends \RuntimeException
{
	public function __construct(
		string $message = 'Templates are managed via git on this environment and cannot be edited here.',
	) {
		parent::__construct($message);
	}
}
