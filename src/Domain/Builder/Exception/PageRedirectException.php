<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Exception;

/**
 * Thrown from a template (`cms.redirect()`) to answer the URL being rendered
 * with a redirect instead of a page — a forwarding URL whose target depends
 * on data, such as a badge link that goes to a member's profile while it is
 * public and to the home page once it is not.
 *
 * PageRouterMiddleware catches it and answers with the status and a
 * `Location` header, no body. A page record's `redirectTo` covers fixed
 * redirects; this is for the ones a template has to work out.
 *
 * Twig wraps exceptions thrown inside a render in its own RuntimeError, so
 * callers look for it with find() rather than instanceof.
 */
class PageRedirectException extends \RuntimeException
{
	public function __construct(public readonly string $url, public readonly int $status = 302)
	{
		parent::__construct(sprintf('Redirect to %s', $url), $status);
	}

	/**
	 * Find this exception in a throwable or its `previous` chain. Twig
	 * re-throws template exceptions wrapped in Twig\Error\RuntimeError, once per
	 * include/embed level, so the one that reaches the middleware is seldom
	 * the one that was thrown.
	 */
	public static function find(\Throwable $throwable): ?self
	{
		for ($current = $throwable; $current instanceof \Throwable; $current = $current->getPrevious()) {
			if ($current instanceof self) {
				return $current;
			}
		}

		return null;
	}
}
