<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Access;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;

/**
 * Memberships: a product can put its buyer in an access group.
 *
 * A group is held while something the customer bought backs it: a
 * subscription that is live, or a product paid for once whose order has not
 * been fully refunded. {@see sync()} works that out from everything the
 * customer has, not from the one thing that just happened. So two
 * subscriptions that give the same group cannot take it from each other,
 * and running it twice changes nothing.
 *
 * The allow-list is the security boundary. Only groups named in Settings →
 * Commerce → Groups a Product May Grant are ever added or removed, and the
 * admin group never is. Every other group a customer is in is left alone.
 *
 * A group on that list belongs to the store's sales: a customer holds it
 * exactly while a purchase backs it. One put in such a group by hand, with
 * nothing bought, is taken out of it the next time their purchases change.
 */
final readonly class AccessGrantService
{
	/** An order in one of these states is still the buyer's. One in dispute is, until the dispute is lost. */
	private const PAID = ['paid', 'partially_refunded', 'disputed'];

	/** A customer's orders are read to see what they have paid for. More than this, and the oldest are not counted. */
	private const ORDERS_READ = 2000;

	private const GROUPS = 'groups';

	public function __construct(
		private CommerceSettings $settings,
		private OrderRepository $orders,
		private SubscriptionRepository $subscriptions,
		private ObjectFetcher $objects,
		private ObjectUpdater $updater,
		private FileLock $lock,
		private ActorContext $actors,
	) {
	}

	/** Whether a product may give this group: it is on the allow-list, and is not the admin group. */
	public function isGrantable(string $group): bool
	{
		return $group !== '' && $group !== UserValidationService::ADMINGROUP && in_array($group, $this->settings->grantableGroups(), true);
	}

	/**
	 * The grantable groups the customer's purchases back right now.
	 *
	 * @return list<string>
	 */
	public function entitledGroups(string $collection, string $customerId): array
	{
		$groups = [];
		foreach ($this->subscriptions->liveForCustomer($collection, $customerId) as $subscription) {
			foreach ($subscription->grantedGroups() as $group) {
				$groups[$group] = true;
			}
		}

		foreach ($this->orders->forCustomer($collection, $customerId, self::ORDERS_READ) as $order) {
			if (!in_array($order->paymentStatus(), self::PAID, true)) {
				continue;
			}
			foreach ($order->lines() as $line) {
				// A plan's group is held by its subscription, not by the order that started it.
				$group = ($line['billing'] ?? 'once') === 'once' ? ($line['accessGroup'] ?? '') : '';
				if (is_string($group) && $group !== '') {
					$groups[$group] = true;
				}
			}
		}

		return array_values(array_filter(array_keys($groups), $this->isGrantable(...)));
	}

	/**
	 * Make the customer's groups match what they have paid for: every
	 * grantable group they are entitled to is there, every other grantable
	 * group is not, and no other group is touched.
	 *
	 * @return list<string> the groups their purchases back
	 *
	 * @throws CommerceException `grant_failed` when the account is gone or cannot be written
	 */
	public function sync(string $collection, string $customerId): array
	{
		if ($collection === '' || $customerId === '' || !$this->objects->existsObject($collection, $customerId)) {
			throw CommerceException::grantFailed();
		}

		$entitled  = $this->entitledGroups($collection, $customerId);
		$grantable = $this->settings->grantableGroups();

		try {
			$this->lock->withLock("{$collection}/{$customerId}", function () use ($collection, $customerId, $entitled, $grantable): void {
				$user = $this->objects->fetchObjectFromDisk($collection, $customerId)->toArray();
				$had  = array_values(array_filter(is_array($user[self::GROUPS] ?? null) ? $user[self::GROUPS] : [], is_string(...)));
				$now  = array_values(array_unique([...array_diff($had, $grantable), ...$entitled]));

				$sameSet = array_diff($had, $now) === [] && array_diff($now, $had) === [];
				if ($sameSet) {
					return;
				}

				$user[self::GROUPS] = $now;
				$this->actors->runAs(Actor::system(), fn () => $this->updater->updateObject($collection, $customerId, $user));
			});
		} catch (CommerceException $e) {
			throw $e;
		} catch (\Throwable) {
			throw CommerceException::grantFailed();
		}

		return $entitled;
	}

	/**
	 * Whether an order gives its buyer a group: a paid-for-once line that
	 * names one. Such an order is delivered by {@see sync()}.
	 */
	public function grants(OrderData $order): bool
	{
		foreach ($order->lines() as $line) {
			if (is_string($line['accessGroup'] ?? null) && $line['accessGroup'] !== '') {
				return true;
			}
		}

		return false;
	}
}
