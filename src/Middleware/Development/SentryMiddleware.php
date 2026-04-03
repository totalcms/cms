<?php

namespace TotalCMS\Middleware\Development;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sentry\Event;
use Sentry\EventHint;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use TotalCMS\Domain\License\Exception\LicenseException;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Support\Version;

class SentryMiddleware implements MiddlewareInterface
{
	public const SALT = 's3ntryR0cks';

	/** Domains to completely ignore (corrupted/problematic installations) */
	private const IGNORED_DOMAINS = [
		'komiksdiner',
	];

	/** @var array<string,mixed> */
	private const DEFAULT_OPTIONS = [
		'dsn'                  => 'p16xTYgwpMx9Z9UBsuOuqV7N7v9NgKpf_3RN7XSvTAiFs3OQXJcSlY5n4IGK-4dbKnAhOvY59eZujBuqmIJN7kAlximb86OwSyrMs9lzODhTfr6jMGXQp2Vs1fLlHRY',
		'traces_sample_rate'   => 0,
		'profiles_sample_rate' => 0,
		'ignore_exceptions'    => [
			HttpNotFoundException::class,
			HttpMethodNotAllowedException::class,
			HttpUnauthorizedException::class,
			HttpForbiddenException::class,
			HttpBadRequestException::class,
			\League\Csv\SyntaxError::class,
			\League\Flysystem\UnableToCreateDirectory::class,
			\League\Flysystem\UnableToMoveFile::class,
			\League\Flysystem\CorruptedPathDetected::class,
			\FastRoute\BadRouteException::class, // Duplicate route registration - user configuration issue
			\Opis\JsonSchema\Exceptions\InvalidKeywordException::class, // Invalid schema definition - user error
			\ParseError::class, // Corrupted PHP files - user installation issue
			\ArgumentCountError::class, // Constructor mismatch - stale deployment or corrupted installation
			\DI\DependencyException::class, // Corrupted installation - missing classes or version mismatch
			\DI\NotFoundException::class,
		],
		'user_error_exceptions' => [
			\DomainException::class,
			\InvalidArgumentException::class,
			\RuntimeException::class,
			\UnexpectedValueException::class,
			\League\Flysystem\UnableToWriteFile::class,
			\TypeError::class,
			\Error::class,
			LicenseException::class,
		],
		'user_error_messages' => [
			'Required field(s) cannot be empty',
			'already exists in',
			'Unable to delete schema',
			'Schema Validation Failed',
			'Collection does not exist',
			'Invalid email',
			'Invalid JSON structure',
			'duplicate column names',
			'must be unique',
			'Cannot override a built-in template',
			'Cannot delete a built-in template',
			'File was only partially uploaded',
			'No file found in request',
			'Unknown format',
			'Upload target path is not writable',
			'it could not be created: Read-only file system',
			'Permission denied',
			'contains invalid json type',
			'File name too long',
			'Uploaded file already moved',
			'Invalid Collection data provided',
			'Invalid URL',
			'Deck must be a dictionary of named objects',
			'Unable to fetch object',
			'installation has been corrupted',
			'Invalid SVG content',
			'must contain an ID or schema must have autogen',
			'Collection for Schema not found',
			'Does not match object ID',
			'Unable to retrieve file data',
			'Unable to locate object property',
			'Error fetching Collection with id',
			// PHP file upload errors
			'File exceeds upload_max_filesize',
			'Uploaded file exceeds MAX_FILE_SIZE',
			'No file was uploaded',
			'Missing temporary upload directory',
			'Failed to write file to disk',
			'File upload stopped by PHP extension',
			// Server configuration errors
			'Class "finfo" not found',
			'No space left on device',
			// Corrupted installation - missing class/function files or DI wiring mismatch
			'Callable TotalCMS\\',
			'" not found',
			'must be of type',
			'Failed opening required',
			// License API rate limiting (not a bug, user/bot issue)
			'Rate limit exceeded',
			// License API unreachable (server network issue, not a bug)
			'License validation failed and no cached data available',
			// Bot/scanner probing for non-existent collections
			'Collection not found',
			// User schema misconfiguration
			'is marked as unique but is not included in the schema index',
			// User Twig template passing null to adapter methods
			'TotalCMSTwigAdapter',
		],
	];

	/** @var array<string,mixed> */
	private static array $filterConfig = [];

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function __construct(private readonly bool $enabled = true)
	{
	}

	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler,
	): ResponseInterface {
		if ($this->enabled) {
			self::initSentry();
		}

		return $handler->handle($request);
	}

	public static function initSentry(): void
	{
		$options = self::DEFAULT_OPTIONS;

		// Store config for use in before_send callback
		self::$filterConfig = $options;

		// Decode the DSN
		$options['dsn'] = Cipher::deobfuscate($options['dsn'], self::SALT);

		// Add release version for tracking errors by release
		$options['release'] = Version::formatted();

		// Set up the before_send callback to filter events
		$options['before_send'] = (static fn (Event $event, ?EventHint $hint): ?Event => self::filterEvent($event, $hint));

		// Remove our custom keys that Sentry doesn't understand
		unset(
			$options['user_error_exceptions'],
			$options['user_error_messages']
		);

		try {
			\Sentry\init($options);

			// Add build hash as a tag for tracking beta builds
			\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
				$scope->setTag('build', Version::build());
			});
		} catch (\Throwable $exception) {
			\Sentry\captureException($exception);

			throw $exception;
		}
	}

	/**
	 * Filter events before sending to Sentry.
	 * Returns null to drop the event, or the event to send it.
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	private static function filterEvent(Event $event, ?EventHint $hint): ?Event
	{
		// Check if this domain should be completely ignored
		$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
		foreach (self::IGNORED_DOMAINS as $domain) {
			if (stripos((string)$host, $domain) !== false) {
				return null;
			}
		}

		if (!$hint instanceof EventHint || $hint->exception === null) {
			return $event;
		}

		$exception = $hint->exception;
		$config    = self::$filterConfig;

		// Check if this exception class should be filtered as a user error
		$userErrorExceptions = $config['user_error_exceptions'] ?? [];
		$userErrorMessages   = $config['user_error_messages'] ?? [];

		foreach ($userErrorExceptions as $exceptionClass) {
			if ($exception instanceof $exceptionClass) {
				// Check if the message matches any user error patterns
				$message = $exception->getMessage();
				foreach ($userErrorMessages as $pattern) {
					if (stripos($message, (string)$pattern) !== false) {
						// This is a user error - don't send to Sentry
						return null;
					}
				}
			}
		}

		return $event;
	}
}
