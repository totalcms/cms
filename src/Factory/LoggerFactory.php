<?php

namespace TotalCMS\Factory;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Factory.
 */
final class LoggerFactory
{
	private string $path;

	private Level $level;

	/**
	 * @var array<HandlerInterface>
	 */
	private array $handler = [];
	private string $format = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";

	private ?LoggerInterface $testLogger = null;

	/**
	 * The constructor.
	 *
	 * @param array<string,mixed> $settings The settings
	 */
	public function __construct(array $settings = [])
	{
		$this->path  = (string)($settings['path'] ?? '');
		$this->level = is_a($settings['level'], Level::class) ? $settings['level'] : Level::Debug;

		if (!empty($this->path) && !is_dir($this->path)) {
			mkdir($this->path, 0777, true);
		}

		// This can be used for testing to make the Factory testable
		if (isset($settings['test'])) {
			$this->testLogger = $settings['test'];
		}
	}

	/**
	 * Build the logger.
	 *
	 * @param string|null $name The logging channel
	 *
	 * @return LoggerInterface The logger
	 */
	public function createLogger(?string $name = null): LoggerInterface
	{
		if (!is_null($this->testLogger)) {
			return $this->testLogger;
		}

		$logger = new Logger($name ?: uuid_create());

		foreach ($this->handler as $handler) {
			$logger->pushHandler($handler);
		}

		$this->handler = [];

		return $logger;
	}

	/**
	 * Add a handler.
	 *
	 * @param HandlerInterface $handler The handler
	 *
	 * @return self The logger factory
	 */
	public function addHandler(HandlerInterface $handler): self
	{
		$this->handler[] = $handler;

		return $this;
	}

	/**
	 * Add rotating file logger handler.
	 *
	 * @param string $filename The filename
	 * @param int $maxFiles Max files before rotated (optional)
	 * @param int $permissions File permissions on file (optional)
	 * @param ?Level $level The level (optional)
	 *
	 * @return self The logger factory
	 */
	public function addFileHandler(string $filename, int $maxFiles = 10, int $permissions = 0777, ?Level $level = null): self
	{
		$filename            = sprintf('%s/%s', $this->path, $filename);
		$level               = $level ?? $this->level;
		$rotatingFileHandler = new RotatingFileHandler($filename, $maxFiles, $level, true, $permissions);

		// The last "true" here tells monolog to remove empty []'s
		$lineFormatter = new LineFormatter($this->format, null, true, true, true);
		$lineFormatter->indentStacktraces('    ');
		$rotatingFileHandler->setFormatter($lineFormatter);

		$this->addHandler($rotatingFileHandler);

		return $this;
	}

	/**
	 * Add a console logger.
	 *
	 * @param Level|null $level The level (optional)
	 *
	 * @return self The logger factory
	 */
	public function addConsoleHandler(?Level $level = null): self
	{
		$streamHandler = new StreamHandler('php://output', $level ?? $this->level);
		$lineFormatter = new LineFormatter($this->format, null, true, true, true);
		$lineFormatter->indentStacktraces('    ');
		$streamHandler->setFormatter($lineFormatter);

		$this->addHandler($streamHandler);

		return $this;
	}
}
