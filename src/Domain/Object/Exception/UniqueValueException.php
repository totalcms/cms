<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Exception;

use TotalCMS\Domain\Translation\TranslatableException;

/**
 * A save found another object in the collection with the same value in a
 * property the schema marks unique. A DomainException, with the English
 * message callers and the Sentry filter have always matched; the visitor
 * gets it in their own language through {@see TranslatableException}.
 */
final class UniqueValueException extends \DomainException implements TranslatableException
{
	public function __construct(
		private readonly string $label,
		private readonly string $value,
	) {
		parent::__construct("{$label} must be unique. The value '{$value}' already exists in this collection.");
	}

	public function translationKey(): string
	{
		return 'object.not_unique';
	}

	/** @return array<string,string> */
	public function translationParameters(): array
	{
		return ['label' => $this->label, 'value' => $this->value];
	}
}
