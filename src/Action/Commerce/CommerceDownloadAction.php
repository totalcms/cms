<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Download\DownloadLinks;
use TotalCMS\Domain\Commerce\Download\Deliverable;
use TotalCMS\Domain\Commerce\Download\Delivery;
use TotalCMS\Domain\Commerce\Download\ProductDownload;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\FileDownloadedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Renderer\FileStreamRenderer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /commerce/download/{token}: hands a buyer the file they paid for.
 *
 * The token is the whole of the buyer's right to the file: it names one
 * line of one order, is signed by this site, and runs out. Behind it the
 * order must still be paid, and the line must have downloads left. Each
 * download is counted on the line, with the order locked, before the file
 * is sent. A download kept somewhere else (a link, a bucket) ends with the
 * buyer sent on to it.
 *
 * Not behind the Commerce gate: goods already paid for must stay
 * downloadable on a store whose add-on has lapsed.
 */
final readonly class CommerceDownloadAction
{
	public function __construct(
		private SignedToken $tokens,
		private OrderRepository $orders,
		private DownloadLinks $links,
		private ProductDownload $downloads,
		private CommerceSettings $settings,
		private FileStreamRenderer $stream,
		private EventDispatcher $events,
		private TwigRenderer $twig,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$payload = $this->tokens->verify(DownloadLinks::PURPOSE, $args['token'] ?? '');
		$orderId = is_string($payload['o'] ?? null) ? $payload['o'] : '';
		$lineId  = is_string($payload['l'] ?? null) ? $payload['l'] : '';
		$order   = $orderId !== '' ? $this->orders->find($orderId) : null;
		$line    = $order instanceof OrderData ? $order->line($lineId) : null;
		if (!$order instanceof OrderData || $line === null) {
			// Made up, changed, run out, or for an order that is gone: all one answer.
			return $this->problem($response, 404, 'invalid');
		}

		if (!$this->links->entitled($order)) {
			return $this->problem($response, 403, 'not_paid', $order);
		}

		$found = $this->links->deliverableFor($line);
		if (!$found instanceof Deliverable) {
			return $this->problem($response, 404, 'no_file', $order);
		}

		if (!$this->count($order->id(), $lineId)) {
			return $this->problem($response, 410, 'used_up', $order);
		}

		return $this->handOver($response, $order, (string)$line['productId'], $found);
	}

	/**
	 * The download is counted: send the file, or send the buyer on to
	 * where it is kept.
	 */
	private function handOver(ResponseInterface $response, OrderData $order, string $productId, Deliverable $found): ResponseInterface
	{
		$delivery = $this->downloads->deliver($productId);
		if (!$delivery instanceof Delivery) {
			return $this->problem($response, 404, 'no_file', $order);
		}

		$this->events->dispatch(CoreEvent::FILE_DOWNLOADED, new FileDownloadedPayload($this->settings->collection('products'), $productId, $found->property, $delivery->name, '', true));

		if ($delivery->isRedirect()) {
			// The file is kept somewhere else. The address is the store's own
			// (the product's link, or one a source signed), never the request's,
			// and the page it came from is not passed along.
			return $response->withStatus(302)
				->withHeader('Location', $delivery->url)
				->withHeader('Cache-Control', 'private, no-store')
				->withHeader('Referrer-Policy', 'no-referrer');
		}

		if (!is_resource($delivery->stream)) {
			return $this->problem($response, 404, 'no_file', $order);
		}

		return $this->stream->download($response->withHeader('Cache-Control', 'private, no-store'), $delivery->mime, $delivery->name, $delivery->stream);
	}

	/**
	 * Count one download on the line, unless it has none left. Checked and
	 * written with the order locked, so two requests at once cannot both
	 * take the last one.
	 */
	private function count(string $orderId, string $lineId): bool
	{
		$counted = false;
		$this->orders->withLock($orderId, function (OrderData $order) use ($lineId, &$counted): OrderData {
			$line = $order->line($lineId);
			if ($line === null || $this->links->remaining($line) < 1) {
				return $order;
			}
			$counted = true;

			// The line's own count, whatever its file is called: a file the
			// store replaces does not start the buyer's allowance again.
			return $order->withLine($lineId, ['downloads' => ['count' => DownloadLinks::used($line) + 1]]);
		});

		return $counted;
	}

	private function problem(ResponseInterface $response, int $status, string $reason, ?OrderData $order = null): ResponseInterface
	{
		return $this->twig->template($response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8'), 'commerce/download-problem.twig', [
			'reason' => $reason,
			'number' => $order?->number(),
		]);
	}
}
