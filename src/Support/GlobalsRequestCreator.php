<?php

declare(strict_types=1);

namespace TotalCMS\Support;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\ServerRequestCreatorInterface;
use Slim\Psr7\Cookies;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Stream;
use Slim\Psr7\UploadedFile;

/**
 * Builds the request App::run() handles, as slim/psr7 does, but survives a
 * header whose name is all digits.
 *
 * `0: x` is a valid header, but PHP turns the `"0"` key from getallheaders()
 * into the integer 0 and slim/psr7 rejects any name that is not a string. That
 * happened inside App::run(), before any middleware or error handler, so a
 * single odd header was a blank 500 on every route (Schemathesis, 2026-10-30).
 * The fallback drops those headers — nothing reads a header named `0` — and
 * builds the request the same way slim/psr7 does. Header-reading cannot be
 * filtered through $_SERVER: under Apache getallheaders() comes from Apache.
 */
final readonly class GlobalsRequestCreator implements ServerRequestCreatorInterface
{
	/** @var \Closure(): array<int|string,string> */
	private \Closure $headers;

	/** @param (\Closure(): array<int|string,string>)|null $headers the request headers; getallheaders() when null */
	public function __construct(?\Closure $headers = null)
	{
		$this->headers = $headers ?? static fn (): array => function_exists('getallheaders') ? (getallheaders() ?: []) : [];
	}

	public function createServerRequestFromGlobals(): ServerRequestInterface
	{
		try {
			return ServerRequestFactory::createFromGlobals();
		} catch (\InvalidArgumentException) {
			return $this->createWithStringNamedHeaders();
		}
	}

	/**
	 * slim/psr7's ServerRequestFactory::createFromGlobals(), minus headers
	 * with integer names. Building a request from globals is the point here.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	private function createWithStringNamedHeaders(): ServerRequestInterface
	{
		$method  = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$headers = new Headers(array_filter(($this->headers)(), is_string(...), ARRAY_FILTER_USE_KEY));
		$cookies = Cookies::parseHeader($headers->getHeader('Cookie', []));

		// php://input cannot be re-read, so slim/psr7 caches it the same way
		$cacheResource = fopen('php://temp', 'wb+');
		$body          = (new StreamFactory())->createStreamFromFile('php://input', 'r', $cacheResource !== false ? new Stream($cacheResource) : null);

		$request = new Request($method, (new UriFactory())->createFromGlobals($_SERVER), $headers, $cookies, $_SERVER, $body, UploadedFile::createFromGlobals($_SERVER));

		$contentType = explode(';', $request->getHeaderLine('Content-Type'))[0];
		if ($method === 'POST' && in_array(trim($contentType), ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
			return $request->withParsedBody($_POST);
		}

		return $request;
	}
}
