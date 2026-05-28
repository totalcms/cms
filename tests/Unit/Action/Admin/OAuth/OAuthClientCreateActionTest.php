<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\OAuth;

use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use TotalCMS\Action\Admin\OAuth\OAuthClientCreateAction;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthClientCreator;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Renderer\JsonRenderer;

/**
 * OAuthClientCreator and PhpSession are both `final` and cannot be mocked.
 * We use real instances: a tmpfile-backed OAuthClientRepository and a
 * PhpSession started against a temp session directory.
 */
final class OAuthClientCreateActionTest extends TestCase
{
	private OAuthClientCreateAction $action;
	private OAuthClientRepository $clients;
	private PhpSession $session;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private string $clientsTmpFile;

	protected function setUp(): void
	{
		$this->clientsTmpFile = sys_get_temp_dir() . '/oauth-clients-create-test-' . uniqid() . '.json';
		$this->clients        = new OAuthClientRepository($this->clientsTmpFile);
		$this->request        = $this->createMock(ServerRequestInterface::class);

		// PhpSession: start only if not already active (tests may share process).
		$this->session = new PhpSession();
		if (!$this->session->isStarted()) {
			$this->session->start();
		}
		$this->session->set(SessionKeys::AUTH_USER, 'admin@example.com');

		$this->action = new OAuthClientCreateAction(
			new OAuthClientCreator($this->clients, new OAuthScopeRegistry(), new OAuthActivityLogger(new NullLogger())),
			$this->session,
			new JsonRenderer(),
		);
	}

	protected function tearDown(): void
	{
		if (is_file($this->clientsTmpFile)) {
			unlink($this->clientsTmpFile);
		}
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function invoke(array $body): Response
	{
		$this->request->method('getParsedBody')->willReturn($body);
		/** @var Response $result */
		$result = ($this->action)($this->request, new Response());

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function decode(Response $response): array
	{
		/** @var array<string,mixed> $data */
		$data = json_decode((string)$response->getBody(), true);

		return $data;
	}

	public function testCreatesClientSuccessfullyAndReturns201(): void
	{
		$response = $this->invoke([
			'name'          => 'My App',
			'redirect_uris' => 'https://myapp.example.com/callback',
			'scopes'        => ['cms:read'],
		]);

		$this->assertSame(201, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('id', $data['client']);
		$this->assertArrayHasKey('secret', $data['client']);
		// Verify client was persisted
		$this->assertNotNull($this->clients->find($data['client']['id']));
	}

	public function testReturns400WhenNameIsEmpty(): void
	{
		$response = $this->invoke([
			'name'          => '',
			'redirect_uris' => 'https://myapp.example.com/callback',
		]);

		$this->assertSame(400, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertArrayHasKey('error', $data);
		$this->assertStringContainsString('Name', $data['error']['message']);
	}

	public function testReturns400WhenNoRedirectUrisProvided(): void
	{
		$response = $this->invoke([
			'name'          => 'My App',
			'redirect_uris' => '',
		]);

		$this->assertSame(400, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertArrayHasKey('error', $data);
		$this->assertStringContainsString('redirect', $data['error']['message']);
	}

	public function testReturns400WhenCreatorThrowsInvalidArgumentException(): void
	{
		// Unknown scope triggers InvalidArgumentException in OAuthClientCreator
		$response = $this->invoke([
			'name'          => 'My App',
			'redirect_uris' => 'https://myapp.example.com/callback',
			'scopes'        => ['unknown-nonexistent-scope'],
		]);

		$this->assertSame(400, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertArrayHasKey('error', $data);
		$this->assertStringContainsString('Unknown scope', $data['error']['message']);
	}

	public function testWhitespaceSplitsRedirectUrisFromTextareaInput(): void
	{
		$response = $this->invoke([
			'name'          => 'My App',
			'redirect_uris' => "https://a.test/cb\nhttps://b.test/cb",
			'scopes'        => [],
		]);

		$this->assertSame(201, $response->getStatusCode());
		$data = $this->decode($response);
		$this->assertSame(
			['https://a.test/cb', 'https://b.test/cb'],
			$data['client']['redirect_uris'],
		);
	}

	public function testUsesSessionAuthUserAsCreatedBy(): void
	{
		$response = $this->invoke([
			'name'          => 'My App',
			'redirect_uris' => 'https://myapp.example.com/callback',
			'scopes'        => [],
		]);

		$this->assertSame(201, $response->getStatusCode());
		$data   = $this->decode($response);
		$client = $this->clients->find($data['client']['id']);
		$this->assertNotNull($client);
		$this->assertSame('admin@example.com', $client->createdBy);
	}
}
