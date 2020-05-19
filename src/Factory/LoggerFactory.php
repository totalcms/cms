<?php

namespace App\Factory;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Factory.
 */
class LoggerFactory
{
    private string $path;
    private string $filename;
    private int $level;

    /**
     * @var StreamHandler[] $handler
     */
    private $handler = [];

    /**
     * The constructor.
     *
     * @param mixed[] $settings The settings
     */
    public function __construct(array $settings)
    {
        $this->path     = (string) $settings['path'];
        $this->level    = (int) $settings['level'];
        $this->filename = (string) $settings['filename'] ?? 'totalcms.log';
    }

    /**
     * Build the logger.
     *
     * @param string $name The name
     *
     * @return LoggerInterface The logger
     */
    public function createInstance(string $name) : LoggerInterface
    {
        $this->addFileHandler();
        $logger = new Logger($name);

        foreach ($this->handler as $handler) {
            $logger->pushHandler($handler);
        }

        $this->handler = [];

        return $logger;
    }

    /**
     * Add rotating file logger handler.
     *
     * @param string $filename The filename
     * @param int    $level    The level (optional)
     *
     * @return LoggerFactory The logger factory
     */
    public function addFileHandler(?string $filename = null, int $level = null) : self
    {
        $filename            = sprintf('%s/%s', $this->path, $filename ?? $this->filename);
        $rotatingFileHandler = new RotatingFileHandler($filename, 0, $level ?? $this->level, true, 0777);

        // The last "true" here tells monolog to remove empty []'s
        $rotatingFileHandler->setFormatter(new LineFormatter(null, null, false, true));

        $this->handler[] = $rotatingFileHandler;

        return $this;
    }

    /**
     * Add a console logger.
     *
     * @param int $level The level (optional)
     *
     * @return self The instance
     */
    public function addConsoleHandler(int $level = null) : self
    {
        $streamHandler = new StreamHandler('php://stdout', $level ?? $this->level);
        $streamHandler->setFormatter(new LineFormatter(null, null, false, true));

        $this->handler[] = $streamHandler;

        return $this;
    }
}
