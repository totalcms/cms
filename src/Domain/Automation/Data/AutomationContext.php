<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;

/**
 * The only API surface an automation handler receives. Pre-injected services
 * plus per-trigger payload data. Schedule runs leave `request`/`event` null;
 * webhook runs populate `request`, event runs populate `event`.
 */
final class AutomationContext
{
	/**
	 * @param array<string,mixed> $trigger the trigger row that fired this run
	 * @param array<string,mixed> $args caller inputs (webhook query+body / manual run args)
	 * @param array<string,mixed>|null $event event payload (event triggers only)
	 */
	public function __construct(
		public readonly IndexReader $indexReader,
		public readonly ObjectFetcher $objectFetcher,
		public readonly ObjectSaver $objectSaver,
		public readonly ObjectUpdater $objectUpdater,
		public readonly ObjectRemover $objectRemover,
		public readonly EmailService $mailer,
		public readonly Config $config,
		public readonly LoggerInterface $logger,
		public readonly array $trigger = [],
		public readonly array $args = [],
		public readonly ?ServerRequestInterface $request = null,
		public readonly ?array $event = null,
	) {
	}
}
