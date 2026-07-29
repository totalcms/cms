<?php

declare(strict_types=1);

namespace TotalCMS\Action\XmlRpc;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * GET handler for the XML-RPC path.
 *
 * The bare response text is copied verbatim from WordPress because MarsEdit
 * validates a typed endpoint URL by looking for exactly that string. `?rsd`
 * serves the discovery document, which is how a client handed only a site URL
 * finds the endpoint — including on subfolder installs where the path is not at
 * the domain root.
 */
readonly class XmlRpcDiscoveryAction
{
	public function __construct(
		private RawRenderer $renderer,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if (($this->config->xmlrpc['enable'] ?? false) !== true) {
			return $response->withStatus(404);
		}

		$endpoint = rtrim($this->config->api, '/') . '/xmlrpc.php';

		if (array_key_exists('rsd', $request->getQueryParams()) || $request->getUri()->getQuery() === 'rsd') {
			return $this->renderer->render(
				$response->withHeader('Content-Type', 'application/rsd+xml; charset=UTF-8'),
				$this->rsd($endpoint)
			);
		}

		return $this->renderer->render(
			$response->withHeader('Content-Type', 'text/plain; charset=UTF-8'),
			'XML-RPC server accepts POST requests only.'
		);
	}

	private function rsd(string $endpoint): string
	{
		$escaped = htmlspecialchars($endpoint, ENT_XML1 | ENT_COMPAT, 'UTF-8');
		$home    = htmlspecialchars(rtrim($this->config->api, '/'), ENT_XML1 | ENT_COMPAT, 'UTF-8');

		// `apiLink`/`api name="WordPress"` is what clients match on. Advertise the
		// blogid-selecting endpoint: a discovering client has no collection to pin.
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">'
			. '<service>'
			. '<engineName>Total CMS</engineName>'
			. '<engineLink>https://www.totalcms.co/</engineLink>'
			. '<homePageLink>' . $home . '</homePageLink>'
			. '<apis>'
			. '<api name="WordPress" blogID="" preferred="true" apiLink="' . $escaped . '" />'
			. '<api name="MetaWeblog" blogID="" preferred="false" apiLink="' . $escaped . '" />'
			. '</apis>'
			. '</service></rsd>';
	}
}
