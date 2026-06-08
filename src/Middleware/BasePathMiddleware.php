<?php

declare(strict_types=1);

namespace TotalCMS\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use TotalCMS\Support\BasePath;

/**
 * Detect the URL prefix the front controller is mounted at and tell Slim
 * about it via App::setBasePath().
 *
 * Replaces selective/basepath, which hard-codes `dirname($SCRIPT_NAME, 2)` —
 * fine for the Symfony/Laravel layout (`/myapp/public/index.php` served at
 * `/myapp/...`) but wrong for installs that don't hide a `public/` segment
 * (e.g. `/tcms/index.php` served at `/tcms/...`, the Composer subpath layout).
 *
 * The actual computation lives in {@see BasePath} so that `config->api`
 * (which builds URLs) resolves the prefix identically to the way Slim strips
 * it here — the two must never drift apart.
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

		$this->app->setBasePath(BasePath::resolve($scriptName, $requestPath));

		return $handler->handle($request);
	}
}
