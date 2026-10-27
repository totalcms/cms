<?php

namespace TotalCMS\Action\Download;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\LoginUrl;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\FileDownloadedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Property\Service\PropertyFile;
use TotalCMS\Domain\Property\Service\PropertyFileResolver;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\FileStreamRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Serve a property file as an attachment, behind the file's own protection:
 * group-protected files send a visitor to the login page and a member
 * without access to the denied page; password-protected files show the
 * password form and count attempts. Subclasses only say which file.
 */
abstract class DownloadAction
{
	protected const MAX_ATTEMPTS = 25;

	public function __construct(
		protected PropertyFileResolver $resolver,
		protected TwigRenderer $twigRenderer,
		protected FileAccessManager $accessManager,
		protected PhpSession $session,
		protected Config $config,
		protected TranslationService $translator,
		protected FileStreamRenderer $streamRenderer,
		protected EventDispatcher $events,
		protected LoginUrl $loginUrl,
	) {
	}

	/**
	 * @param array<string,string> $args
	 * @param array<string,mixed>  $query
	 */
	abstract protected function resolve(array $args, array $query): PropertyFile;

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$file = $this->resolve($args, $request->getQueryParams());

		if (!$file->exists()) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		$flash = $this->session->getFlash();
		$flash->clear();

		$attempts = $this->session->get(SessionKeys::DOWNLOAD_ATTEMPTS, 0);
		$this->session->set(SessionKeys::DOWNLOAD_ATTEMPTS, $attempts + 1);

		$maxAttempts = $this->config->auth['downloadMaxAttempts'] ?? self::MAX_ATTEMPTS;
		if ($attempts > $maxAttempts) {
			$flash->add('error', $this->translator->trans('flash.download_too_many'));

			return $this->accessDenied($response);
		}

		$file->load($this->accessManager);

		if ($this->accessManager->isProtectedByGroups()) {
			if ($this->accessManager->sessionHasUser() === false) {
				return $this->redirectToLogin($request, $response, $file->collection);
			}
			if ($this->accessManager->userHasAccess() === false) {
				return $this->accessDenied($response);
			}
		}

		if ($this->accessManager->isPasswordProtected()) {
			$password = $this->passwordFromRequest($request);

			if (is_null($password)) {
				return $this->loadPasswordForm($response);
			}
			if ($this->accessManager->verfiyPassword($password) === false) {
				$flash->add('error', $this->translator->trans('flash.download_invalid_password'));

				return $this->loadPasswordForm($response);
			}
		}

		$record = $file->fetch();
		$file->recordDownload($record);

		if ($this->accessManager->isProtectedByGroups()) {
			$this->accessManager->logDownload($file->collection, $file->id, $file->property, $file->name, $file->subpath);
		}

		$this->events->dispatch(CoreEvent::FILE_DOWNLOADED, new FileDownloadedPayload(
			$file->collection,
			$file->id,
			$file->property,
			$record->download,
			(string)$file->subpath,
			$this->accessManager->isProtectedByGroups() || $this->accessManager->isPasswordProtected(),
		));

		$this->session->delete('downloadAttempts');

		return $this->streamRenderer->download($response, $record->mime, $record->download, $file->open());
	}

	private function passwordFromRequest(ServerRequestInterface $request): ?string
	{
		$queryParams = $request->getQueryParams();
		$postData    = (array)$request->getParsedBody();

		if (isset($postData['password'])) {
			return $postData['password'];
		}
		if (isset($queryParams['pwd'])) {
			return Cipher::decrypt($queryParams['pwd']);
		}

		return null;
	}

	/** The file's collection picks the login (its `accessCollection`); the visitor comes back to the file afterwards. */
	private function redirectToLogin(ServerRequestInterface $request, ResponseInterface $response, string $collection): ResponseInterface
	{
		$url = $this->loginUrl->forCollection($collection, (string)$request->getUri());

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	private function accessDenied(ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response->withStatus(403), 'admin/denied.twig');
	}

	private function loadPasswordForm(ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'admin/download-auth.twig');
	}
}
