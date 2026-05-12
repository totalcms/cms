<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;

/**
 * Detect the URL prefix the front controller is mounted at and tell Slim
 * about it via App::setBasePath().
 *
 * Replaces selective/basepath, which does `dirname($SCRIPT_NAME, 2)` —
 * that's tuned for the Symfony/Laravel layout where the URL hides a
 * `public/` segment (`/myapp/public/index.php` served at `/myapp/...`).
 * T3 deploys the docroot AS the public dir, so subpath installs like
 * `/tcms/index.php` need `dirname × 1` instead. Using the third-party
 * middleware silently fails for subpath — Slim then can't strip the
 * prefix during route matching and `/tcms/admin` 404s.
 *
 * Logic:
 *   SCRIPT_NAME = `/index.php`        -> basePath = ''     (root mount)
 *   SCRIPT_NAME = `/tcms/index.php`   -> basePath = '/tcms'  (subpath mount)
 *   SCRIPT_NAME = `/cms/v2/index.php` -> basePath = '/cms/v2'  (deeper subpath)
 */
final readonly class BasePathMiddleware implements MiddlewareInterface
{
	/**
	 * @param App<ContainerInterface> $app
	 */
	public function __construct(private App $app)
	{
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		$serverParams = $request->getServerParams();
		$scriptName   = (string)($serverParams['SCRIPT_NAME'] ?? '');

		$basePath = $this->computeBasePath($scriptName);
		$this->app->setBasePath($basePath);

		return $handler->handle($request);
	}

	private function computeBasePath(string $scriptName): string
	{
		if ($scriptName === '') {
			return '';
		}

		$basePath = str_replace('\\', '/', dirname($scriptName));

		// dirname returns '/', '.', or '\\' for top-level scripts — all map
		// to "no base path" for routing purposes.
		if (in_array($basePath, ['/', '.', '\\'], true)) {
			return '';
		}

		return $basePath;
	}
}
