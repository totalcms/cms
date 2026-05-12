<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Auth;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Action\Auth\AuthRegisterSubmitAction;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Auth\Service\SessionLogin;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Transformer\ObjectMetaTransformer;

final class AuthRegisterSubmitActionTest extends TestCase
{
	private AuthRegisterSubmitAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $objectSaver;
	private \PHPUnit\Framework\MockObject\MockObject $loginService;
	private \PHPUnit\Framework\MockObject\MockObject $sessionLogin;
	private Config $config;

	protected function setUp(): void
	{
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->objectSaver   = $this->createMock(ObjectSaver::class);
		$this->loginService  = $this->createMock(LoginService::class);
		$this->sessionLogin  = $this->createMock(SessionLogin::class);

		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth = [
			'collection'         => 'admin',
			'publicRegistration' => ['members'],
		];

		$this->action = new AuthRegisterSubmitAction(
			$this->renderer,
			$this->objectSaver,
			$this->loginService,
			$this->sessionLogin,
			$this->config,
		);
	}

	public function testRejectsCollectionNotInAllowList(): void
	{
		// 'admin' is the default auth collection but is NOT in publicRegistration.
		// Must opt-in explicitly.
		$this->objectSaver->expects($this->never())->method('saveObject');
		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');

		$this->expectException(HttpForbiddenException::class);

		($this->action)(
			$this->createRequest([]),
			$this->createMock(ResponseInterface::class),
			['collection' => 'admin'],
		);
	}

	public function testHappyPathSavesAuthenticatesAndReturnsJsonItem(): void
	{
		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();

		$this->objectSaver->expects($this->once())
			->method('saveObject')
			->with('members', $this->callback(static fn (array $d): bool => ($d['email'] ?? '') === 'a@b.test'))
			->willReturn($saved);

		$this->loginService->expects($this->once())
			->method('authenticate')
			->with('a@b.test', 'sekret123', 'members')
			->willReturn(['id' => 'alice']);

		$this->sessionLogin->expects($this->once())
			->method('establish')
			->with('alice', 'members');

		// Response shape mirrors ObjectSaveAction — JsonRenderer::jsonItem with
		// the saved record + ObjectMetaTransformer.
		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	public function testSaveFailureBubblesUpAsForSlimErrorHandler(): void
	{
		// Match ObjectSaveAction's behaviour: don't catch — Slim's error
		// middleware renders the exception as JSON for the AJAX client.
		$this->objectSaver->method('saveObject')
			->willThrowException(new \DomainException('Email already in use'));

		$this->loginService->expects($this->never())->method('authenticate');
		$this->sessionLogin->expects($this->never())->method('establish');
		$this->renderer->expects($this->never())->method('jsonItem');

		$this->expectException(\DomainException::class);

		($this->action)(
			$this->createRequest(['email' => 'taken@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);
	}

	public function testAutoLoginFailureIsSilentResponseStillReturnsUser(): void
	{
		// Save succeeds, then authenticate throws. The user record exists, so
		// the client still gets the JSON payload — it'll just discover the
		// missing session when the next request hits an auth-required endpoint.
		$saved = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$this->objectSaver->method('saveObject')->willReturn($saved);

		$this->loginService->method('authenticate')
			->willThrowException(new \RuntimeException('auth backend down'));

		$this->sessionLogin->expects($this->never())->method('establish');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->anything(), $saved, $this->isInstanceOf(ObjectMetaTransformer::class))
			->willReturn($jsonResponse);

		$result = ($this->action)(
			$this->createRequest(['email' => 'a@b.test', 'password' => 'sekret123']),
			$this->createMock(ResponseInterface::class),
			['collection' => 'members'],
		);

		$this->assertSame($jsonResponse, $result);
	}

	/**
	 * @param array<string,string> $body
	 */
	private function createRequest(array $body): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($body);

		return $request;
	}
}
