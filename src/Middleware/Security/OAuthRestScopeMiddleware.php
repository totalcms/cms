<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeEvaluator;

/**
 * Gates Bearer-authenticated REST API requests by OAuth scope.
 *
 * The OAuthBearerMiddleware mounted upstream (outer layer on the /api/ group)
 * validates the JWT and sets oauth_scopes / oauth_client_id request attributes.
 * This middleware reads them, builds an operation string from the request's
 * method+path ("{METHOD} {path}"), and asks OAuthScopeEvaluator::isRestPathAllowed()
 * whether the token's scopes grant access via the impliedPaths regex patterns.
 *
 * Pass-through when no Bearer token is present (oauth_scopes attribute absent)
 * so the existing DualAuthMiddleware / ApiKeyAuthMiddleware / AuthMiddleware
 * flows still handle those requests unchanged.
 *
 * Scope rejection returns 403 with:
 *   WWW-Authenticate: Bearer realm="T3", error="insufficient_scope"
 */
readonly class OAuthRestScopeMiddleware implements MiddlewareInterface
{
	/**
	 * @param App<ContainerInterface> $app
	 */
	public function __construct(
		private App $app,
		private OAuthScopeEvaluator $scopeEvaluator,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$scopes = $request->getAttribute('oauth_scopes');
		if (!is_array($scopes)) {
			// No Bearer token validated upstream — let existing auth chain handle it.
			return $handler->handle($request);
		}

		/** @var list<string> $scopeList */
		$scopeList = array_values(array_map(
			static fn (mixed $s): string => is_object($s) && method_exists($s, 'getIdentifier')
				? (string)$s->getIdentifier()
				: (string)$s,
			$scopes,
		));

		// Strip the mount prefix so the scope regexes (which expect /api/...)
		// match on subfolder installs where the URI carries a basePath like
		// /tcms. Same approach as SetupCheckMiddleware.
		$path      = $this->stripBasePath($request->getUri()->getPath());
		$operation = $request->getMethod() . ' ' . $path;

		if (!$this->scopeEvaluator->isRestPathAllowed($scopeList, $operation)) {
			$clientId = (string)$request->getAttribute('oauth_client_id', '');
			$this->activityLogger->scopeRejected($clientId, $operation, $scopeList);

			$body = json_encode([
				'error' => [
					'message'   => 'OAuth token scopes do not permit this REST operation.',
					'operation' => $operation,
				],
			]) ?: '{}';

			return (new Response(403))
				->withHeader('Content-Type', 'application/json')
				->withHeader('WWW-Authenticate', 'Bearer realm="T3", error="insufficient_scope"')
				->withBody(Stream::create($body));
		}

		return $handler->handle($request);
	}

	/**
	 * Remove the app's basePath prefix from a request path so the scope
	 * regexes match on subfolder installs. Returns the path unchanged when
	 * there's no basePath or the path doesn't start with it.
	 */
	private function stripBasePath(string $path): string
	{
		$basePath = $this->app->getBasePath();

		if ($basePath === '' || !str_starts_with($path, $basePath)) {
			return $path;
		}

		$stripped = substr($path, strlen($basePath));

		return $stripped === '' ? '/' : $stripped;
	}
}
