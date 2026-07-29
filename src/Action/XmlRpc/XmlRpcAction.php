<?php

declare(strict_types=1);

namespace TotalCMS\Action\XmlRpc;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\XmlRpc\Service\MethodRouter;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcFault;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcRequestParser;
use TotalCMS\Domain\XmlRpc\Transport\XmlRpcResponseWriter;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

/**
 * WordPress-compatible XML-RPC endpoint.
 *
 * Faults are returned with HTTP 200 per the XML-RPC spec — clients parse the
 * body, not the status. Request bodies are NEVER logged: the caller's API key
 * travels inside the payload as the `password` param.
 */
readonly class XmlRpcAction
{
	public function __construct(
		private XmlRpcRequestParser $parser,
		private XmlRpcResponseWriter $writer,
		private MethodRouter $router,
		private RawRenderer $renderer,
		private Config $config,
		private LoggerFactory $loggerFactory,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		if (($this->config->xmlrpc['enable'] ?? false) !== true) {
			return $response->withStatus(404);
		}

		$logger     = $this->loggerFactory->channelLogger(LogChannel::XmlRpc);
		$collection = ($args['collection'] ?? '') !== '' ? $args['collection'] : null;
		$method     = 'unparsed';

		try {
			$call   = $this->parser->parse((string)$request->getBody());
			$method = $call['method'];
			$xml    = $this->writer->methodResponse($this->router->dispatch($method, $call['params'], $collection));
		} catch (XmlRpcFault $fault) {
			$logger->info('XML-RPC fault', [
				'method'     => $method,
				'collection' => $collection,
				'code'       => (int)$fault->getCode(),
			]);
			$xml = $this->writer->fault((int)$fault->getCode(), $fault->getMessage());
		} catch (\Throwable $error) {
			$logger->error('XML-RPC handler error', [
				'method'    => $method,
				'exception' => $error::class,
				'message'   => $error->getMessage(),
			]);
			$xml = $this->writer->fault(500, 'Total CMS could not complete the request.');
		}

		return $this->renderer->render(
			$response->withHeader('Content-Type', 'text/xml; charset=UTF-8'),
			$xml
		);
	}
}
