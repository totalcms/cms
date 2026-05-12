<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Factory\LoggerFactory;

/**
 * Runs a page's declared middleware chain in order. First middleware to
 * return a Response wins — subsequent middleware do not run, and the page
 * does not render.
 *
 * Failure semantics:
 *   - **Unknown name** (typo, uninstalled extension): logged as a warning,
 *     skipped. The chain continues. Better than 500-ing the whole page.
 *   - **Middleware throws**: logged as an error, the chain returns a 500
 *     response. Auth/security middleware throwing has to fail closed.
 */
readonly class PageMiddlewareRunner
{
	private LoggerInterface $logger;

	public function __construct(
		private PageMiddlewareRegistry $registry,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('builder.log')->createLogger('builder');
	}

	/**
	 * Run the page's middleware chain. Returns the short-circuit response
	 * if any middleware produced one, or null to indicate "proceed to render.".
	 */
	public function run(ServerRequestInterface $request, PageData $page): ?ResponseInterface
	{
		if ($page->middleware === []) {
			return null;
		}

		foreach ($page->middleware as $name) {
			$middleware = $this->registry->resolve($name);
			if (!$middleware instanceof \TotalCMS\Domain\Builder\PageMiddleware\PageMiddlewareInterface) {
				$this->logger->warning('Skipping unknown page middleware', [
					'name' => $name,
					'page' => $page->id,
				]);

				continue;
			}

			try {
				$response = $middleware->handle($request, $page);
			} catch (\Throwable $e) {
				$this->logger->error('Page middleware threw — failing closed', [
					'name'  => $name,
					'page'  => $page->id,
					'error' => $e->getMessage(),
				]);

				return $this->failClosed($e->getMessage());
			}

			if ($response instanceof ResponseInterface) {
				return $response;
			}
		}

		return null;
	}

	/**
	 * Build a 500 response for a thrown middleware. Plain text body — the
	 * Twig pipeline isn't safe to use here (the failure may have come from
	 * the Twig pipeline itself).
	 */
	private function failClosed(string $message): ResponseInterface
	{
		$factory  = new \Nyholm\Psr7\Factory\Psr17Factory();
		$response = $factory->createResponse(500)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->getBody()->write('Page middleware error: ' . $message);

		return $response;
	}
}
