<?php

namespace TotalCMS\Domain\License\Exception;

use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Data\EditionFeature;

/**
 * Exception thrown when a feature is not available for the current license edition.
 */
class EditionFeatureException extends \Exception
{
	public function __construct(
		public readonly EditionFeature $feature,
		public readonly Edition $requiredEdition,
		public readonly Edition $currentEdition,
		?string $message = null,
	) {
		$message ??= sprintf(
			'The "%s" feature requires %s edition or higher. Current edition: %s',
			$feature->label(),
			ucfirst($requiredEdition->value),
			ucfirst($currentEdition->value)
		);

		parent::__construct($message, 403);
	}

	/**
	 * Get a user-friendly message for display.
	 */
	public function getUserMessage(): string
	{
		return sprintf(
			'This feature requires the %s edition. Please upgrade your license to access %s.',
			ucfirst($this->requiredEdition->value),
			$this->feature->label()
		);
	}
}
