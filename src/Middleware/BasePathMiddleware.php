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
 * Replaces selective/basepath, which hard-codes `dirname($SCRIPT_NAME, 2)` —
 * fine for the Symfony/Laravel layout (`/myapp/public/index.php` served at
 * `/myapp/...`) but wrong for installs that don't hide a `public/` segment
 * (e.g. `/tcms/index.php` served at `/tcms/...`, the Composer subpath layout).
 *
 * Two layouts ship with T3 and they need opposite stripping depths:
 *
 *   1. Classic "public dir hidden" layout (Stacks plugin, Symfony-style):
 *      SCRIPT_NAME = `/rw_common/plugins/stacks/tcms/public/index.php`
 *      URL         = `/rw_common/plugins/stacks/tcms/admin`
 *      basePath    = `/rw_common/plugins/stacks/tcms`  (strip `/public/index.php`)
 *
 *   2. Composer subpath layout (front controller AT the URL mount):
 *      SCRIPT_NAME = `/tcms/index.php`
 *      URL         = `/tcms/admin`
 *      basePath    = `/tcms`  (strip just `/index.php`)
 *
 * SCRIPT_NAME alone can't tell us which we're in, so we cross-check against
 * REQUEST_URI: try the script's own directory first, fall back to its parent
 * if the URL clearly mounts one level higher (the canonical public/ pattern).
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
		$requestPath  = $request->getUri()->getPath();

		$basePath = $this->computeBasePath($scriptName, $requestPath);
		$this->app->setBasePath($basePath);

		return $handler->handle($request);
	}

	private function computeBasePath(string $scriptName, string $requestPath): string
	{
		if ($scriptName === '') {
			return '';
		}

		$scriptName = str_replace('\\', '/', $scriptName);

		// First candidate: the directory the script physically lives in.
		// Matches the Composer subpath layout and the root install.
		$scriptDir = $this->normalizeDir(dirname($scriptName));
		if ($scriptDir !== '' && $this->uriMountedAt($requestPath, $scriptDir)) {
			return $scriptDir;
		}

		// Second candidate: one level above the script's directory. Covers
		// the classic Symfony/Laravel/Stacks pattern where `public/` is
		// hidden by a docroot rewrite, so URLs come in without it.
		$parentDir = $this->normalizeDir(dirname($scriptDir));
		if ($parentDir !== '' && $this->uriMountedAt($requestPath, $parentDir)) {
			return $parentDir;
		}

		return '';
	}

	private function normalizeDir(string $dir): string
	{
		$dir = str_replace('\\', '/', $dir);

		// dirname returns '/', '.', or '\\' at the top — all map to "no base path".
		if (in_array($dir, ['/', '.', '\\'], true)) {
			return '';
		}

		return $dir;
	}

	private function uriMountedAt(string $requestPath, string $prefix): bool
	{
		return $requestPath === $prefix || str_starts_with($requestPath, $prefix . '/');
	}
}
