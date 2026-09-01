<?php

namespace TotalCMS\Traits;

use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LogFile;
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
		$this->logger ??= $this->createLogger();

		return $this->logger;
	}

	/**
	 * Create a logger instance using LoggerFactory.
	 *
	 * The channel is dynamic (the consuming class name), so this writes to
	 * the central app log directly rather than going through a LogChannel
	 * case. Override in services that need a real channel.
	 */
	protected function createLogger(): LoggerInterface
	{
		return $this->loggerFactory
			->addFileHandler(LogFile::App->value)
			->createLogger(static::class);
	}

	/**
	 * LoggerFactory instance - must be injected via constructor in implementing classes.
	 */
	protected LoggerFactory $loggerFactory;
}
