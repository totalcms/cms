<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Commerce;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Keeps the ordinary collection API away from the store's records.
 *
 * Orders, transactions, carts and subscriptions are records of money, and
 * only the store writes them: every change to one through the collection and
 * object routes is refused, for every caller. A super admin and an API key
 * are refused like anyone else, and so is a site with authentication off,
 * because an edited total is wrong whoever typed it. Staff change an order
 * through the commerce admin, which goes through the engine. The imports
 * that name a collection are refused here too: a queued import runs later as
 * the site itself, which the storage layer's own rule lets through.
 *
 * It also refuses three settings that would undo that: opening one of those
 * collections, or the discount codes, to the public (a readable discounts
 * collection lists every code), and letting the public count a product's
 * stock up or down.
 *
 * A collection is guarded by its schema, not its name. A site that had an
 * `orders` collection of its own before Commerce is not touched, and a
 * collection on an order schema is guarded whatever it is called.
 */
final readonly class CommerceCollectionGuardMiddleware implements MiddlewareInterface
{
	/** @var list<string> */
	private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	/** Routes that change a collection's settings, not its objects. */
	private const SETTINGS_ROUTES = ['collection-save', 'collection-update', 'collection-patch'];

	/** Deleting a whole collection is the operator's call, and rebuilding an index changes no record. */
	private const ALLOWED_ROUTES = ['collection-delete', 'collection-reindex'];

	private const STOCK = 'stock';

	public function __construct(
		private CollectionFetcher $collections,
		private JsonRenderer $jsonRenderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$route = RouteContext::fromRequest($request)->getRoute();
		if (!$route instanceof RouteInterface || !in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
			return $handler->handle($request);
		}

		$name = (string)$route->getName();
		if (in_array($name, self::ALLOWED_ROUTES, true)) {
			return $handler->handle($request);
		}

		$body       = $request->getParsedBody();
		$body       = is_array($body) ? $body : [];
		$collection = (string)($route->getArgument('collection') ?? '');

		$refusal = in_array($name, self::SETTINGS_ROUTES, true)
			? $this->settingsRefusal($this->schemaOf($collection !== '' ? $collection : $this->text($body['id'] ?? ''), $body), $body)
			: $this->objectRefusal($this->schemaOf($collection, []));

		return $refusal === null ? $handler->handle($request) : $this->refuse(...$refusal);
	}

	/**
	 * The schema the collection is on, or is being put on by this request.
	 *
	 * @param array<mixed> $body
	 */
	private function schemaOf(string $collection, array $body): string
	{
		$named = $this->text($body['schema'] ?? '');
		if ($named !== '') {
			return $named;
		}

		$existing = $collection === '' ? null : $this->collections->fetchCollection($collection);

		return $existing instanceof CollectionData ? $existing->schema : '';
	}

	/** @return array{0: string, 1: string}|null a code and a message, or null to let the request through */
	private function objectRefusal(string $schema): ?array
	{
		if (!SchemaData::isSystemOnly($schema)) {
			return null;
		}

		return ['commerce_collection_readonly', 'Orders, transactions, carts and subscriptions are written by the store and cannot be changed here.'];
	}

	/**
	 * @param array<mixed> $body
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function settingsRefusal(string $schema, array $body): ?array
	{
		$private = SchemaData::isSystemOnly($schema) || in_array($schema, SchemaData::COMMERCE_SECRET_SCHEMAS, true);
		if ($private && ($body['publicOperations'] ?? []) !== []) {
			return ['commerce_collection_private', 'This collection holds store records and cannot be opened to the public.'];
		}

		if ($schema === 'commerce-product' && $this->opensStock($body)) {
			return ['commerce_stock_not_public', 'Stock cannot be a public counter: anyone could count it up or down.'];
		}

		return null;
	}

	/** @param array<mixed> $body */
	private function opensStock(array $body): bool
	{
		$properties = $body['properties'] ?? null;
		$stock      = is_array($properties) ? ($properties[self::STOCK] ?? null) : null;
		$settings   = is_array($stock) ? ($stock['settings'] ?? null) : null;

		return is_array($settings) && filter_var($settings['publicIncrement'] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}

	private function refuse(string $code, string $message): ResponseInterface
	{
		return $this->jsonRenderer->json(
			$this->responseFactory->createResponse()->withStatus(403),
			['error' => ['code' => $code, 'message' => $message]],
		);
	}
}
