<?php

namespace TotalCMS\Traits;

use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LoggerFactory;

/**
 * Trait for services that need logging capabilities.
 * Provides standardized logger creation using the same pattern as DefaultErrorHandler.
 */
trait LoggerAwareTrait
{
	private ?LoggerInterface $logger = null;

	/**
	 * Get or create a logger instance for this service.
	 * Uses the same LoggerFactory pattern as DefaultErrorHandler.
	 */
	protected function getLogger(): LoggerInterface
	{
		if ($this->logger === null) {
			$this->logger = $this->createLogger();
		}

		return $this->logger;
	}

	/**
	 * Create a logger instance using LoggerFactory.
	 * Override this method in services that need custom log files.
	 */
	protected function createLogger(): LoggerInterface
	{
		return $this->loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger(static::class);
	}

	/**
	 * LoggerFactory instance - must be injected via constructor in implementing classes.
	 */
	protected LoggerFactory $loggerFactory;
}
