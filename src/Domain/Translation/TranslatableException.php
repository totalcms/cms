<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Translation;

/**
 * An exception whose message a visitor reads. DefaultErrorHandler answers it
 * in the request's language from the `admin` catalog, while getMessage()
 * stays English for logs and for Sentry's user-error filter, which matches
 * on the English text.
 */
interface TranslatableException extends \Throwable
{
	/** A key in the `admin` translation catalog. */
	public function translationKey(): string;

	/** @return array<string,string> Placeholder values, named without braces */
	public function translationParameters(): array;
}
