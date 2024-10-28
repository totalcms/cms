<?php

namespace TotalCMS\Action\Download;

use Nyholm\Psr7\Stream;
use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Utils\Cipher;

final class DownloadFileAction
{
	public function __construct(
		private FileFetcher $fileFetcher,
		private TwigRenderer $twigRenderer,
		private FileAccessManager $accessManager,
		private ObjectSaver $objectSaver,
		private PhpSession $session,
	) {
	}

	public const MAX_ATTEMPTS = 10;

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection  = $args['collection'];
		$id          = $args['id'];
		$property    = $args['property'];

		if (!$this->fileFetcher->fileExists($collection, $id, $property)) {
			throw new HttpNotFoundException($request, 'File not found');
		}

		// Clear all flash messages
		$flash = $this->session->getFlash();
		$flash->clear();

		$attempts = $this->session->get('downloadAttempts', 0);
		$this->session->set('downloadAttempts', $attempts + 1);

		$maxAttempts = self::MAX_ATTEMPTS;

		if ($attempts > $maxAttempts) {
			$flash->add('error', 'Too many download attempts');

			return $this->accessDenied($response);
		}

		$this->accessManager->loadFile($collection, $id, $property);

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
				$flash->add('error', 'Invalid password');

				return $this->loadPasswordForm($response);
			}
		}

		$file     = $this->fileFetcher->fetchFile($collection, $id, $property);
		$response = $response->withHeader('Content-Type', $file->mime)
			->withHeader('Content-Disposition', "attachment; filename={$file->name}");

		// increment the download count
		$file->count = $file->count + 1;
		$this->objectSaver->updateObjectProperty($collection, $id, $property, $file->transform());

		$stream = $this->fileFetcher->streamFile($collection, $id, $property);

		$this->session->delete('downloadAttempts');

		return $response->withBody(Stream::create($stream));
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
