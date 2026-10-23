<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Exception;

/**
 * Thrown from a template (`cms.notFound()`) to say the URL being rendered has
 * no page behind it after all — a collection URL that matched a draft, a
 * record the template decides not to serve, a lookup that came back empty.
 *
 * PageRouterMiddleware catches it and answers the request the way it answers
 * any unmatched URL: the site's 404 page rendered in place, HTTP 404, the
 * visitor's URL untouched. That is the honest response for crawlers and link
 * checkers; `redirectIfNotFound` bounces to the 404 page with a 302 instead,
 * which reads as "temporarily moved" and hides the broken URL.
 *
 * Twig wraps exceptions thrown inside a render in its own RuntimeError, so
 * callers look for it with find() rather than instanceof.
 */
class PageNotFoundException extends \RuntimeException
{
	public function __construct(string $message = 'Page not found.')
	{
		parent::__construct($message, 404);
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
