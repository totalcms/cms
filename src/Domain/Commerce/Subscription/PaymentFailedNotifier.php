<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;

/**
 * Tells a subscriber their renewal payment did not go through. The email
 * is sent by the job queue, with the store's failed-payment mailer.
 *
 * Not final and not readonly, so a test can put one in its place.
 */
class PaymentFailedNotifier
{
	public function __construct(
		private readonly JobQueuer $jobs,
		private readonly CommerceSettings $settings,
	) {
	}

	public function notify(SubscriptionData $subscription): void
	{
		$mailer = $this->settings->paymentFailedMailer();
		if ($mailer === '' || $subscription->email() === '') {
			return;
		}

		$this->jobs->queueEmail([
			'mailerId'   => $mailer,
			'collection' => $this->settings->collection('subscriptions'),
			'objectId'   => $subscription->id(),
			'overrideTo' => $subscription->email(),
		]);
	}
}
