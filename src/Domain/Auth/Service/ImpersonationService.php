<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Exception\ImpersonationException;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Lets a super-admin temporarily become another user. Stashes the real
 * identity in SessionKeys::IMPERSONATOR, then swaps the active user via
 * SessionLogin so every access check (which reads AUTH_USER) sees the target.
 */
final class ImpersonationService implements ImpersonationServiceInterface
{
	private LoggerInterface $logger;

	public function __construct(
		private readonly SessionInterface $session,
		private readonly SessionLogin $sessionLogin,
		private readonly UserValidationService $userValidation,
		private readonly ObjectFetcher $objectFetcher,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Access);
	}

	public function isImpersonating(): bool
	{
		return $this->session->has(SessionKeys::IMPERSONATOR);
	}

	/** @return array{userId: string, collection: string}|null */
	public function impersonator(): ?array
	{
		$data = $this->session->get(SessionKeys::IMPERSONATOR);

		return (is_array($data) && isset($data['userId'], $data['collection']))
			? ['userId' => (string)$data['userId'], 'collection' => (string)$data['collection']]
			: null;
	}

	public function start(string $collection, string $userId): void
	{
		$realUser       = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		$defaultCollection = (string)($this->config->auth['collection'] ?? 'auth');
		$realCollection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? $defaultCollection);

		if (!$this->userValidation->isSuperAdmin($realUser)) {
			throw new ImpersonationException('Only super-admins may impersonate.');
		}
		if ($this->isImpersonating()) {
			throw new ImpersonationException('Already impersonating — stop first.');
		}
		if ($collection === $realCollection && $userId === $realUser) {
			throw new ImpersonationException('You cannot impersonate yourself.');
		}
		// Only the operator collection and opt-in public-registration (member)
		// collections hold real users. Never swap the session identity into an
		// arbitrary collection (e.g. a blog post id) — the UI hides this, and the
		// server enforces it so a crafted POST cannot produce a nonsensical session.
		$allowedCollections = array_merge(
			[$defaultCollection],
			array_values((array)($this->config->auth['publicRegistration'] ?? [])),
		);
		if (!in_array($collection, $allowedCollections, true)) {
			throw new ImpersonationException('That collection cannot be impersonated.');
		}
		if (!$this->objectFetcher->existsObject($collection, $userId)) {
			throw new ImpersonationException('That user does not exist.');
		}
		if ($this->userValidation->isSuperAdmin($userId)) {
			throw new ImpersonationException('You cannot impersonate another super-admin.');
		}

		// Stash BEFORE the swap. establish() regenerates the session id but
		// migrates data, so the key survives.
		$this->session->set(SessionKeys::IMPERSONATOR, ['userId' => $realUser, 'collection' => $realCollection]);
		$this->sessionLogin->establish($userId, $collection, false);

		$this->logger->info('Impersonation started', [
			'actor'            => $realUser,
			'target'           => $userId,
			'targetCollection' => $collection,
		]);
	}

	public function stop(): void
	{
		$real = $this->impersonator();
		if ($real === null) {
			return;
		}

		$this->sessionLogin->establish($real['userId'], $real['collection'], false);
		$this->session->delete(SessionKeys::IMPERSONATOR);

		$this->logger->info('Impersonation stopped', ['actor' => $real['userId']]);
	}
}
