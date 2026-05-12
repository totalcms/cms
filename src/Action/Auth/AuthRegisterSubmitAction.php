<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

/**
 * Public registration endpoint. Creates a user in an opted-in auth collection,
 * auto-logs them in, and returns the saved object — same response shape as
 * {@see \TotalCMS\Action\Object\ObjectSaveAction} so the standard form builder
 * (`cms.form.builder('members', {register: true})`) can chain deferred image
 * uploads and post-save actions against the new record without any special
 * casing on the client side.
 *
 * The collection MUST appear in `$config->auth['publicRegistration']` —
 * otherwise the endpoint throws a 403. That allow-list is empty by default so
 * the default admin auth collection isn't accidentally exposed to public
 * signups.
 *
 * Security caveats the operator owns:
 *  - Anyone who reaches this endpoint can create a user record. Add a CAPTCHA,
 *    rate limit, or email-verification gate before exposing it on a site
 *    where the resulting account grants meaningful access.
 *  - New users land in whatever default access group the auth collection's
 *    schema assigns. If that group reaches gated content, every signup
 *    (including bot signups) gains that access.
 *  - Password validation (minimum length etc.) is the schema's responsibility.
 */
readonly class AuthRegisterSubmitAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private ObjectSaver $objectSaver,
		private LoginService $loginService,
		private SessionLogin $sessionLogin,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'] ?? (string)($this->config->auth['collection'] ?? '');
		$allowed    = (array)($this->config->auth['publicRegistration'] ?? []);

		if (!in_array($collection, $allowed, true)) {
			// 403 with a generic message — don't leak whether the collection
			// exists. Slim's error handler renders it as JSON for AJAX clients.
			throw new HttpForbiddenException($request, 'Public registration is not enabled for this collection.');
		}

		$data = (array)$request->getParsedBody();

		// Save the user record. Errors (validation, duplicate id, etc.) bubble
		// up to Slim's error handler — same as ObjectSaveAction.
		$user = $this->objectSaver->saveObject($collection, $data);

		// Auto-login: best-effort. If it throws (auth backend down, race with a
		// just-created record, etc.), the account still exists and the client
		// can prompt the user to sign in. Image uploads in the form builder
		// chain may then 401 if they require auth — that's the price of
		// keeping the save and the session establishment loosely coupled.
		try {
			$authenticated = $this->loginService->authenticate(
				(string)($data['email'] ?? ''),
				(string)($data['password'] ?? ''),
				$collection,
			);
			$this->sessionLogin->establish((string)$authenticated['id'], $collection);
		} catch (\Throwable) {
			// Silent: response still includes the saved user.
		}

		return $this->renderer->jsonItem($response, $user, new ObjectMetaTransformer());
	}
}
