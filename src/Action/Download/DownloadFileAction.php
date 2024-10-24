<?php

namespace TotalCMS\Action\Download;

use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\Property\Service\FileFetcher;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use Slim\Routing\RouteContext;

final class DownloadFileAction
{
	public function __construct(
		private FileFetcher $fileFetcher,
		private TwigRenderer $twigRenderer,
		private FileAccessManager $accessManager,
	) {
	}

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

		$this->accessManager->loadFile($collection, $id, $property);

		if ($this->accessManager->isProtectedByGroups()) {
			// check if the user is logged in and has access to the file
			if (!$this->accessManager->sessionHasUser()) {
				return $this->redirectToLogin($request, $response);
			}
			if (!$this->accessManager->userHasAccess()) {
				return $this->accessDenied($response);
			}
		}

		if ($this->accessManager->isPasswordProtected()) {
			$password = $this->passwordFromRequest($request);

			if (is_null($password)) {
				return $this->loadPasswordForm($response);
			}
			if (!$this->accessManager->verfiyPassword($password)) {
				return $this->accessDenied($response);
			}
		}

		$file     = $this->fileFetcher->fetchFile($collection, $id, $property);
		$response = $response->withHeader('Content-Type', $file->mime)
			->withHeader('Content-Disposition', "attachment; filename='{$file->name}'");

		$stream = $this->fileFetcher->streamFile($collection, $id, $property);

		return $response->withBody(Stream::create($stream));
	}

	private function passwordFromRequest(ServerRequestInterface $request): ?string
	{
		$queryParams = $request->getQueryParams();
		$postData    = (array)$request->getParsedBody();

		$password = null;

		if ($postData['password']) {
			$password = $postData['password'];
		} elseif (isset($queryParams['password'])) {
			$password = base64_decode($queryParams['password']);
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
