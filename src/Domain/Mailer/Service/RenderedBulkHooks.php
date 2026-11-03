<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mailer\Service;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Mailer\Data\RenderedBulkMessage;

/**
 * Runs a rendered bulk message's recipient filter and variable provider,
 * resolved from the container by class name when the job runs.
 */
class RenderedBulkHooks
{
	public function __construct(private readonly ContainerInterface $container)
	{
	}

	/** @param array<string,mixed> $object */
	public function accepts(RenderedBulkMessage $message, array $object): bool
	{
		if ($message->recipientFilter === null) {
			return true;
		}

		return $this->resolve($message->recipientFilter, BulkRecipientFilter::class)->accepts($object);
	}

	/**
	 * @param array<string,mixed> $object
	 *
	 * @return array{vars: array<string,mixed>, headers: array<string,string>}
	 */
	public function variables(RenderedBulkMessage $message, array $object): array
	{
		if ($message->variables === null) {
			return ['vars' => [], 'headers' => []];
		}

		$result = $this->resolve($message->variables, BulkVariableProvider::class)->variables($object);

		return ['vars' => $result['vars'], 'headers' => $result['headers']];
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $interface
	 *
	 * @return T
	 */
	private function resolve(string $class, string $interface): object
	{
		$service = $this->container->get($class);
		if (!$service instanceof $interface) {
			throw new \LogicException(sprintf('%s does not implement %s', $class, $interface));
		}

		return $service;
	}
}
