<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Applies {@see BotProtection} to an anonymous write: the public registration
 * endpoint (`register`) and public-submission object creates and clones
 * (`create`).
 *
 * The check runs only when the body carries either bot field or the
 * collection has **Require Bot Protection** on, so a form built before the
 * option existed keeps working. A signed-in caller (session login, API key,
 * OAuth bearer — decided from server-side state, never from the body) skips
 * it entirely. The two bot fields leave the body either way.
 *
 * A failed check is never announced: the caller answers with a decoy built
 * by {@see self::decoy()} in the same shape as a real success, and the
 * reason goes to the access log at info level. The one exception is an
 * expired token: its signature is good, so the form really came from this
 * site and a person may be behind it — they get a visible 422 asking them
 * to reload ({@see self::expiredResponse()}).
 */
final readonly class BotSubmissionGate
{
	public const EXPIRED_MESSAGE = 'This form has expired. Reload the page and try again.';

	private LoggerInterface $logger;

	public function __construct(
		private BotProtection $protection,
		private CollectionFetcher $collectionFetcher,
		private ObjectFactory $objectFactory,
		private ObjectFetcher $objectFetcher,
		private ApiKeyAuthenticator $apiKeys,
		private ?SessionInterface $session,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Access);
	}

	/**
	 * @param array<string,mixed> $body
	 *
	 * @return array{0: array<string,mixed>, 1: string} the body without the bot fields, and '' when it passes or the reason it failed
	 */
	public function screen(ServerRequestInterface $request, array $body, string $collection, string $formType): array
	{
		$stripped = $this->protection->strip($body);

		if ($this->signedIn($request)) {
			return [$stripped, ''];
		}

		if (!$this->protection->present($body) && !$this->required($collection)) {
			return [$stripped, ''];
		}

		$reason = $this->protection->check($body, $collection, $formType);
		if ($reason !== '') {
			$this->logger->info('Bot submission turned away', [
				'reason'     => $reason,
				'collection' => $collection,
				'form'       => $formType,
				'path'       => $request->getUri()->getPath(),
			]);
		}

		return [$stripped, $reason];
	}

	/**
	 * The object a real save would have returned, built from the submitted
	 * data and never written.
	 *
	 * @param array<string,mixed> $data
	 */
	public function decoy(string $collection, array $data): ObjectData
	{
		$object = $this->objectFactory->generateObject($collection, $data);
		// Mirror ObjectSaver: a real save of an existing id fails, so the decoy
		// must too, or reposting a known id would tell a decoy from a save.
		if (isset($object->id) && $object->id !== '' && $this->objectFetcher->existsObject($collection, $object->id)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $object->id, $collection));
		}

		return $object;
	}

	/**
	 * The object a real clone would have returned: the source under its new
	 * id, refused the same way when the target id is taken. Never written.
	 */
	public function cloneDecoy(string $collection, string $fromId, string $toId): ObjectData
	{
		$object = $this->objectFetcher->fetchObject($collection, $fromId);
		if ($this->objectFetcher->existsObject($collection, $toId)) {
			throw new \DomainException(sprintf('Object with id %s already exists in %s', $toId, $collection));
		}
		$object->id = $toId;

		return $object;
	}

	public static function expired(string $reason): bool
	{
		return $reason === BotProtection::REASON_EXPIRED;
	}

	/**
	 * 422 with {@see self::EXPIRED_MESSAGE}, in the shape DefaultErrorHandler
	 * gives any error: an HTML fragment for htmx, JSON `error.message`
	 * otherwise. Not thrown, so it is not logged as an application error.
	 */
	public function expiredResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		if ($request->getHeaderLine('HX-Request') === 'true') {
			$response->getBody()->write('<div class="cms-error cms-error-422" role="alert"><p>' . htmlspecialchars(self::EXPIRED_MESSAGE, ENT_QUOTES, 'UTF-8') . '</p></div>');
			$response = $response->withHeader('Content-Type', 'text/html');
		} else {
			$response->getBody()->write((string)json_encode(['error' => ['message' => self::EXPIRED_MESSAGE]]));
			$response = $response->withHeader('Content-Type', 'application/json');
		}

		return $response
			->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
			->withStatus(422);
	}

	/**
	 * Whether bot protection applies to a form rendered for this visitor: not
	 * when someone is signed in. Forms are rendered from the session only.
	 */
	public static function sessionSignedIn(?SessionInterface $session): bool
	{
		return $session instanceof SessionInterface && (string)($session->get(SessionKeys::AUTH_USER) ?? '') !== '';
	}

	private function required(string $collection): bool
	{
		$data = $this->collectionFetcher->fetchCollection($collection);

		return $data instanceof CollectionData && $data->requireBotProtection;
	}

	/**
	 * A public submission skips DualAuthMiddleware's credential checks, so an
	 * API key on a public route is never resolved there: validate it here, so
	 * a script creating objects with its key is not mistaken for a bot.
	 */
	private function signedIn(ServerRequestInterface $request): bool
	{
		if (in_array($request->getAttribute('authMethod'), ['session', 'apikey', 'oauth_bearer'], true)) {
			return true;
		}

		if ($request->getAttribute('oauth_access_token_id') !== null) {
			return true;
		}

		if (self::sessionSignedIn($this->session)) {
			return true;
		}

		return $this->apiKeys->hasApiKeyHeader($request) && $this->apiKeys->authenticate($request) instanceof ApiKeyData;
	}
}
