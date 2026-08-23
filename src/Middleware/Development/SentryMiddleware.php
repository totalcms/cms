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

	/** @var array<string,mixed> */
	private const DEFAULT_OPTIONS = [
		'dsn'                  => 'p16xTYgwpMx9Z9UBsuOuqV7N7v9NgKpf_3RN7XSvTAiFs3OQXJcSlY5n4IGK-4dbKnAhOvY59eZujBuqmIJN7kAlximb86OwSyrMs9lzODhTfr6jMGXQp2Vs1fLlHRY',
		'traces_sample_rate'   => 0,
		'profiles_sample_rate' => 0,
		// Privacy guarantees: no cookies, session data, client IPs, or auth
		// user info (send_default_pii) and no request bodies — the SDK's
		// default body size is 'medium', which would attach small POST
		// payloads, i.e. CMS content. Only the exception, stack trace, and
		// request URL/method reach the monitoring service.
		'send_default_pii'      => false,
		'max_request_body_size' => 'none',
		'ignore_exceptions'     => [
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
			\Opis\JsonSchema\Exceptions\UnresolvedReferenceException::class, // User schema $ref points at a missing/external schema
			\ParseError::class, // Corrupted PHP files - user installation issue
			\ArgumentCountError::class, // Constructor mismatch - stale deployment or corrupted installation
			// Every Symfony Console exception is a CLI-usage mistake at the
			// terminal (unknown command/namespace, unknown option, wrong arg
			// count) — never a T3 bug. A genuine failure inside a command body
			// throws an application exception, not a Console\Exception.
			\Symfony\Component\Console\Exception\ExceptionInterface::class,
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
			// PHP warnings/fatals promoted to exceptions (includes Sentry's
			// FatalErrorException). Only dropped when the message matches a
			// known environment pattern below — e.g. "Failed to open stream"
			// includes on half-uploaded installs, "Permission denied" writes.
			\ErrorException::class,
		],
		'user_error_messages' => [
			'Required field(s) cannot be empty',
			'already exists in',
			'Unable to delete schema',
			'is reserved',
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
			'Cannot save object with a reserved name',
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
			// session_start() returned false: session.save_path is missing,
			// unwritable, or the account is out of inodes/quota. Seen on shared
			// hosting where crawler traffic fills the session directory. PHP
			// could not give us a session; there is nothing for T3 to fix.
			'Failed to start the session',
			// Filesystem-level locking conflict — typically when the install
			// lives on iCloud Drive / Dropbox / OneDrive where the sync daemon
			// holds locks that conflict with PHP's file writes (EDEADLK).
			'Resource deadlock avoided',
			// Corrupted installation - missing class/function files or DI wiring mismatch
			'Callable TotalCMS\\',
			'" not found',
			'must be of type',
			// Covers both wordings PHP uses when a file the autoloader or
			// bootstrap expected is absent: require says "Failed opening
			// required 'x'", include says "Failed opening 'x' for inclusion".
			// Either way a file that shipped with the package is missing, so
			// the install is incomplete — a truncated upload or a partial zip
			// extraction, not something T3 can fix at runtime.
			'Failed opening',
			// Free-function from a vendor namespace undefined — only happens
			// when Composer's `files` autoload didn't load (incomplete upload,
			// corrupted autoload_files.php, vendor package missing entirely).
			// T3 code doesn't call vendor namespaced free functions directly,
			// so realistic sources of this in production are all broken installs.
			'Call to undefined function',
			// Method exists in one file but not its declaring class on disk —
			// caused by partial uploads where the caller got the new version
			// but the callee is still the old version. PHPStan catches real
			// missing-method bugs in T3 CI before merge, so by the time this
			// reaches production Sentry it's overwhelmingly install drift.
			'Call to undefined method',
			// License API rate limiting (not a bug, user/bot issue)
			'Rate limit exceeded',
			// License API unreachable (server network issue, not a bug)
			'License validation failed and no cached data available',
			// Bot/scanner probing for non-existent collections
			'Collection not found',
			// Built-in or user schema missing from the install — either the
			// operator deleted a schema in production, the install is corrupted,
			// or the data dir lives on a sync-managed filesystem (iCloud,
			// Dropbox, etc.) where the schema cache has been evicted.
			'Schema type does not exist',
			// File-save endpoint hit on a property whose schema type isn't a
			// file-class (image/file/depot/gallery). Schema misconfiguration
			// on the customer's side — the system is correctly rejecting an
			// invalid request, not a T3 bug.
			'Unknown saver service type for object',
			// User schema misconfiguration
			'is marked as unique but is not included in the schema index',
			// User Twig template passing null to adapter methods
			'TotalCMSTwigAdapter',
			// Logging infrastructure couldn't open its file — the data dir or
			// <datadir>/.system/logs isn't writable/present on this install
			// (permissions, or a sync-managed filesystem). An environment/
			// operator issue, not a T3 bug; a failed log write must not page us.
			'could not be opened in append mode',
			// Bot/user passed a malformed UUID to the MCP endpoint — input
			// validation correctly rejecting bad input, not a bug.
			'Invalid UUID',
			// Object data carries invalid byte sequences (bad import / paste);
			// schema validation correctly refuses to re-encode it.
			'Malformed UTF-8 characters',
			// User schema references a property type that doesn't exist.
			'Unknown property type for object',
			// Operator never ran `tcms oauth:setup`, so the OAuth signing key is
			// missing/invalid (see OAuthServerFactory::loadSigningKey).
			'OAuth signing key is missing or invalid',
			// Request body stream couldn't be read — a truncated/aborted upload
			// or client disconnect, not a server bug.
			'Could not read from stream',
			// include/require/fopen warnings on files that should exist —
			// half-uploaded or corrupted installs (missing vendor files, absent
			// config). Same family as 'Failed opening required' above.
			'Failed to open stream',
			// Corrupted vendor tree — an abstract class resolved where its
			// concrete provider file is missing (e.g. broken Faker install).
			'Cannot instantiate abstract class',
			// User Twig template calling an adapter/function without its
			// required arguments (surfaces as a shutdown fatal, so the
			// ArgumentCountError ignore above doesn't catch it).
			'Too few arguments to function',
			// User Twig template arithmetic on incompatible values — e.g.
			// `{{ user-response }}` parses as subtraction, not a variable name.
			'Unsupported operand types',
			// A chunk vanished from tmp between upload and assembly — host tmp
			// cleaner or a same-named concurrent upload, not a T3 bug.
			'Unable to open chunk file',
			// Template/form references a depot file that no longer exists on
			// disk — operator deleted or moved user data.
			'Unable to load file from depot',
			// Filesystem refuses a recursive delete (permissions / sync-managed
			// storage) — same family as the Permission denied pattern above.
			'Unable to delete directory',
			// Correct rejection of a save aimed at a singleton collection that
			// already holds its object (form resubmission / API misuse).
			'is a singleton and already holds its object',
			// Correct rejection of a file save aimed at a nonexistent object.
			'does not exist in collection',
			// Stored JSON hand-edited or imported with a wrong-typed field —
			// the serializer is correctly refusing it, and the operator sees
			// the message directly. T3's writers can't produce these shapes.
			'Failed to denormalize attribute',
		],
	];

	/**
	 * DI / container-resolution exceptions. On the WEB these are pure noise —
	 * almost always a bot hitting a half-uploaded site (container.php and class
	 * files briefly out of sync), so they're ignored. On the CLI there are no
	 * bots, and the same exception means a real, actionable failure: a wiring
	 * regression that hard-downs a command (e.g. `jobs:process`). So these are
	 * surfaced in CLI context and ignored only in web context.
	 *
	 * @var array<class-string>
	 */
	private const WEB_ONLY_IGNORE = [
		\DI\DependencyException::class,
		\DI\NotFoundException::class,
		\DI\Definition\Exception\InvalidDefinition::class,
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

	/**
	 * The effective ignore list for the given context. Web ignores the DI /
	 * install-corruption family; CLI surfaces it (see {@see WEB_ONLY_IGNORE}).
	 *
	 * @return array<class-string>
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public static function ignoredExceptions(bool $cli = false): array
	{
		$ignore = self::DEFAULT_OPTIONS['ignore_exceptions'];

		if (!$cli) {
			return array_merge($ignore, self::WEB_ONLY_IGNORE);
		}

		return $ignore;
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public static function initSentry(bool $cli = false): void
	{
		$options = self::DEFAULT_OPTIONS;

		// Surface DI/container failures on the CLI; keep ignoring them on the web.
		$options['ignore_exceptions'] = self::ignoredExceptions($cli);

		// Store config for use in before_send callback (uses the same list, so
		// the belt-and-suspenders filter stays consistent with the native one).
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
		if (!$hint instanceof EventHint || $hint->exception === null) {
			return $event;
		}

		$exception = $hint->exception;
		$config    = self::$filterConfig;

		// Drop exceptions thrown from third-party extension code. Extensions
		// live at `tcms-data/extensions/{vendor}/{name}/` and are owned by
		// their authors — T3 can't fix bugs in their code, so reporting them
		// in T3's Sentry just adds noise. Match on the canonical Unix path
		// segment plus the Windows variant for cross-platform installs.
		$file = $exception->getFile();
		if (str_contains($file, '/tcms-data/extensions/') || str_contains($file, '\\tcms-data\\extensions\\')) {
			return null;
		}

		// Belt-and-suspenders on `ignore_exceptions`. The Sentry SDK applies
		// this option natively, but its `IgnoreErrorsIntegration` doesn't
		// reliably walk deeply-chained exceptions (e.g. PHP-DI wraps its own
		// `InvalidDefinition` 4 levels deep when an autowire chain fails),
		// so events that should be dropped occasionally slip through to
		// Sentry. Re-check here against the same list — `instanceof` will
		// match the outermost exception's class even when the SDK's chain
		// walk misses it.
		$ignoreExceptions = $config['ignore_exceptions'] ?? [];
		foreach ($ignoreExceptions as $exceptionClass) {
			if ($exception instanceof $exceptionClass) {
				return null;
			}
		}

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
