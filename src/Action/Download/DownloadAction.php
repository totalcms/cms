<?php

namespace TotalCMS\Action\Download;

use Nyholm\Psr7\Stream;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

abstract class DownloadAction
{
	protected TwigRenderer $twigRenderer;
	protected FileAccessManager $accessManager;
	protected ObjectUpdater $objectUpdater;
	protected PhpSession $session;
	protected Config $config;
	protected TranslationService $translator;

	protected string $collection;
	protected string $id;
	protected string $property;
	protected string $name;
	protected ?string $subpath = null;

	protected const MAX_ATTEMPTS = 25;

	abstract protected function fileExists(): bool;

	abstract protected function loadFile(): void;

	abstract protected function fetchFile(): FileData;

	abstract protected function incrementCount(FileData $file): void;

	/** @return resource */
	abstract protected function streamFile();

	/**
	 * Decode filename from URL, supporting both + and %20 encoding.
	 */
	private function decodeFilename(string $filename): string
	{
		// Handle both + and %20 style encoding for maximum compatibility
		// urldecode handles %20, then we handle + for form-style encoding
		return str_replace('+', ' ', urldecode($filename));
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$this->collection = $args['collection'];
		$this->id         = $args['id'];
		$this->property   = $args['property'];
		$this->name       = $this->decodeFilename($args['name'] ?? '');

		$query          = $request->getQueryParams();
		$this->subpath  = $query['path'] ?? null;

		if (!$this->fileExists()) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		// Clear all flash messages
		$flash = $this->session->getFlash();
		$flash->clear();

		$attempts = $this->session->get(SessionKeys::DOWNLOAD_ATTEMPTS, 0);
		$this->session->set(SessionKeys::DOWNLOAD_ATTEMPTS, $attempts + 1);

		$maxAttempts = $this->config->auth['downloadMaxAttempts'] ?? self::MAX_ATTEMPTS;

		if ($attempts > $maxAttempts) {
			$flash->add('error', $this->translator->trans('flash.download_too_many'));

			return $this->accessDenied($response);
		}

		$this->loadFile();

		if ($this->accessManager->isProtectedByGroups()) {
			// check if the user is logged in and has access to the file
			if ($this->accessManager->sessionHasUser() === false) {
				return $this->redirectToLogin($request, $response);
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

		$file = $this->fetchFile();
		$this->incrementCount($file);

		// Log download if file is protected by groups
		if ($this->accessManager->isProtectedByGroups()) {
			$this->accessManager->logDownload($this->collection, $this->id, $this->property, $this->name, $this->subpath);
		}

		$this->session->delete('downloadAttempts');

		// Close session before streaming to release file locks
		$this->session->save();

		// Clean any output buffers to prevent memory bloat on shared hosting
		while (ob_get_level() > 0) {
			ob_end_clean();
		}

		$response = $response->withHeader('Content-Type', $file->mime)
			->withHeader('Content-Disposition', "attachment; filename=\"{$file->download}\"")
			->withHeader('X-Accel-Buffering', 'no');

		return $response->withBody(Stream::create($this->streamFile()));
	}

	private function passwordFromRequest(ServerRequestInterface $request): ?string
	{
		$queryParams = $request->getQueryParams();
		$postData    = (array)$request->getParsedBody();

		$password = null;

		if (isset($postData['password'])) {
			$password = $postData['password'];
		} elseif (isset($queryParams['pwd'])) {
			$password = Cipher::decrypt($queryParams['pwd']);
		}

		return $password;
	}

	private function redirectToLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$router = RouteContext::fromRequest($request)->getRouteParser();
		$url    = $router->urlFor('login');

		return $response->withStatus(302)->withHeader('Location', $url);
	}

	private function accessDenied(ResponseInterface $response): ResponseInterface
	{
		$response = $response->withStatus(403);

		return $this->twigRenderer->template($response, 'admin/denied.twig');
	}

	private function loadPasswordForm(ResponseInterface $response): ResponseInterface
	{
		return $this->twigRenderer->template($response, 'admin/download-auth.twig');
	}
}
