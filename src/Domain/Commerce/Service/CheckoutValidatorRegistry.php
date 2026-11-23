<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Contract\CheckoutValidator;
use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The checkout validators extensions have registered.
 *
 * Every one runs; the first refusal is the answer. One that throws refuses
 * the checkout too: a wrong sale is worse than a lost one, so this fails
 * closed where an event listener would be skipped.
 */
final class CheckoutValidatorRegistry
{
	private const FAILED = 'This order could not be checked. Please try again, or contact the store.';

	/** @var list<CheckoutValidator> */
	private array $validators = [];

	private readonly LoggerInterface $logger;

	public function __construct(LoggerFactory $loggerFactory)
	{
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	public function add(CheckoutValidator $validator): void
	{
		$this->validators[] = $validator;
	}

	/** The reason this order may not be checked out, or null when every validator allows it. */
	public function refusal(OrderDraft $draft, PricingContext $context): ?string
	{
		$refusal = null;
		foreach ($this->validators as $validator) {
			try {
				$answer = $validator->validate($draft, $context);
			} catch (\Throwable $e) {
				$this->logger->error('A checkout validator failed; the checkout was refused', ['validator' => $validator::class, 'exception' => $e]);
				$answer = self::FAILED;
			}
			$refusal ??= $answer !== null && trim($answer) !== '' ? trim($answer) : null;
		}

		return $refusal;
	}
}
