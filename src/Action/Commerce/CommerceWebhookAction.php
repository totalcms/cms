<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Commerce\Payment\PaymentProvider;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\ProviderEvent;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Webhook\WebhookEventStore;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST /commerce/webhook/{provider}: where a payment provider tells the
 * store what happened.
 *
 * This route belongs to the store, not to a provider's extension. The
 * provider checks the signature and reads the event; what is done about it
 * (spotting a repeat, locking the order, changing it, fulfilling it) is one
 * implementation for every provider.
 *
 * The status is the message to the provider:
 *
 *   404  no such provider
 *   400  the signature is not good; nothing was read
 *   200  handled, a repeat, or not something the store uses: do not resend
 *   500  handling it failed: send it again
 *
 * An event is remembered only after it has been handled, so a failure can
 * always be retried. It is not behind the Commerce gate: a store whose
 * add-on has lapsed still has to hear about money it already took.
 */
final readonly class CommerceWebhookAction
{
	private LoggerInterface $logger;

	public function __construct(
		private PaymentProviderRegistry $providers,
		private ProviderEventRouter $router,
		private WebhookEventStore $events,
		private JsonRenderer $json,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$provider = $this->providers->get($args['provider'] ?? '');
		if (!$provider instanceof PaymentProvider) {
			throw new HttpNotFoundException($request);
		}

		if (!$this->verified($provider, $request)) {
			$this->logger->warning('A webhook with a bad signature was refused', ['provider' => $provider->id(), 'ip' => (string)($request->getServerParams()['REMOTE_ADDR'] ?? '')]);

			return $this->json->json($response, ['error' => ['code' => 'signature_invalid', 'message' => 'The signature could not be verified.']], 400);
		}

		try {
			$event = $provider->parseWebhook($request);
			if (!$event instanceof ProviderEvent) {
				return $this->json->json($response, ['ignored' => true]);
			}
			if ($this->events->seen($event->eventKey())) {
				return $this->json->json($response, ['duplicate' => true]);
			}
			if (!$this->router->route($event)) {
				// Nothing handles this kind yet. It is not remembered, so the
				// day something does, a retry still reaches it.
				return $this->json->json($response, ['ignored' => true]);
			}
			$this->events->remember($event->eventKey());
		} catch (\Throwable $e) {
			$this->logger->error('A webhook could not be handled; the provider was asked to send it again', ['provider' => $provider->id(), 'exception' => $e]);

			return $this->json->json($response, ['error' => ['code' => 'webhook_failed', 'message' => 'The event could not be handled. Send it again.']], 500);
		}

		return $this->json->json($response, ['ok' => true]);
	}

	/** A verifier answers false for anything it cannot verify; one that throws has verified nothing. */
	private function verified(PaymentProvider $provider, ServerRequestInterface $request): bool
	{
		try {
			return $provider->webhookVerifier()->verify($request);
		} catch (\Throwable) {
			return false;
		}
	}
}
