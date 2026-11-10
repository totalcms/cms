<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * Thrown from a template to answer the URL being rendered with a complete
 * response instead of the page: the Protect passcode prompt, for one.
 *
 * Whatever is rendering catches it. PageRouterMiddleware returns the response;
 * on a Stacks page, TotalCMS::processBufferMacros() sends it in place of the
 * buffered page, which is what lets a Twig function gate a page Apache serves
 * directly, outside the Slim pipeline.
 *
 * Twig wraps exceptions thrown inside a render in its own RuntimeError, so
 * callers look for it with find() rather than instanceof.
 */
class PageResponseException extends \RuntimeException
{
	public function __construct(public readonly ResponseInterface $response)
	{
		parent::__construct(sprintf('Page answered with a %d response', $response->getStatusCode()));
	}

	/** Find this exception in a throwable or its `previous` chain. */
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
