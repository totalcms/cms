<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\PayPal;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Webhook\RawBody;
use TotalCMS\Domain\Webhook\WebhookVerifier;

/**
 * Checks that a webhook came from PayPal.
 *
 * PayPal signs an event with a certificate and sends the signature in five
 * `PAYPAL-*` headers. The store does not check it itself: it hands PayPal
 * the headers, the id of its own webhook and the event, and PayPal answers
 * whether the signature is good for that webhook.
 *
 * The event is passed on as the bytes that arrived. Decoding it and writing
 * it again can change how a number or a character is written, and PayPal
 * then says the signature does not match.
 */
final readonly class PayPalWebhookVerifier implements WebhookVerifier
{
	/** The headers PayPal signs with, and what each is called in the question put to PayPal. */
	private const HEADERS = [
		'PAYPAL-AUTH-ALGO'         => 'auth_algo',
		'PAYPAL-CERT-URL'          => 'cert_url',
		'PAYPAL-TRANSMISSION-ID'   => 'transmission_id',
		'PAYPAL-TRANSMISSION-SIG'  => 'transmission_sig',
		'PAYPAL-TRANSMISSION-TIME' => 'transmission_time',
	];

	/** Where PayPal keeps its certificates. An address anywhere else is not PayPal's. */
	private const CERT_HOST = '/^https:\/\/api(?:-m)?(?:\.sandbox)?\.paypal\.com\//';

	public function __construct(
		private PayPalClient $client,
		private string $webhookId,
	) {
	}

	public function verify(ServerRequestInterface $request): bool
	{
		if ($this->webhookId === '') {
			return false;
		}

		$fields = [];
		foreach (self::HEADERS as $header => $field) {
			$value = $request->getHeaderLine($header);
			if ($value === '') {
				return false;
			}
			$fields[$field] = $value;
		}
		if (preg_match(self::CERT_HOST, $fields['cert_url']) !== 1) {
			return false;
		}

		$raw = RawBody::of($request);
		if (!is_array(json_decode($raw, true))) {
			return false;
		}

		$fields['webhook_id'] = $this->webhookId;
		// Everything but the event is written here; the event goes in as it came.
		$json = substr((string)json_encode($fields, JSON_UNESCAPED_SLASHES), 0, -1) . ',"webhook_event":' . $raw . '}';

		try {
			$answer = $this->client->postJson('/v1/notifications/verify-webhook-signature', $json);
		} catch (ProviderException) {
			return false;
		}

		return ($answer['verification_status'] ?? '') === 'SUCCESS';
	}
}
