<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\OAuth;

use Odan\Session\PhpSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TotalCMS\Action\Admin\OAuth\OAuthClientDeleteAction;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Renderer\JsonRenderer;

final class OAuthClientDeleteActionTest extends TestCase
{
	private OAuthClientDeleteAction $action;
	private OAuthClientRepository $clients;
	private OAuthGrantRepository $grants;
	private JsonRenderer $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;
	private string $clientsTmpFile;
	private string $grantsTmpFile;

	protected function setUp(): void
	{
		$this->clientsTmpFile = sys_get_temp_dir() . '/oauth-clients-delete-test-' . uniqid() . '.json';
		$this->grantsTmpFile  = sys_get_temp_dir() . '/oauth-grants-delete-test-' . uniqid() . '.json';
		$this->clients        = new OAuthClientRepository($this->clientsTmpFile);
		$this->grants         = new OAuthGrantRepository($this->grantsTmpFile);
		$this->jsonRenderer   = new JsonRenderer();
		$this->request        = $this->createMock(ServerRequestInterface::class);
		$this->response       = $this->createMock(ResponseInterface::class);

		$session = new PhpSession();
		if (!$session->isStarted()) {
			$session->start();
		}

		$this->action = new OAuthClientDeleteAction(
			$this->clients,
			$this->grants,
			$this->jsonRenderer,
			$session,
			new OAuthActivityLogger(new NullLogger()),
		);
	}

	protected function tearDown(): void
	{
		if (is_file($this->clientsTmpFile)) {
			unlink($this->clientsTmpFile);
		}
		if (is_file($this->grantsTmpFile)) {
			unlink($this->grantsTmpFile);
		}
	}

	private function makeClient(string $id, string $name = 'Test App'): OAuthClientData
	{
		return new OAuthClientData(
			id:             $id,
			name:           $name,
			secretHash:     '$2y$12$hash',
			redirectUris:   ['https://example.com/callback'],
			scopes:         ['cms:read'],
			isDynamic:      false,
			isConfidential: true,
			createdAt:      '2026-01-01T00:00:00Z',
			createdBy:      'admin',
		);
	}

	private function makeGrant(string $id, string $clientId): OAuthGrantData
	{
		return new OAuthGrantData(
			id:               $id,
			clientId:         $clientId,
			userId:           'user@example.com',
			scopes:           ['cms:read'],
			refreshTokenHash: 'hash-' . $id,
			issuedAt:         '2026-01-01T00:00:00Z',
			expiresAt:        '2027-01-01T00:00:00Z',
		);
	}

	private function makeSlimResponse(): ResponseInterface
	{
		return new \Slim\Psr7\Response();
	}

	public function testDeletesClientSuccessfullyAndReturns200(): void
	{
		$client = $this->makeClient('client-1');
		$this->clients->save($client);

		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => 'client-1']);

		$this->assertSame(200, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertTrue($data['success']);
		$this->assertNull($this->clients->find('client-1'));
	}

	public function testReturns404WhenClientNotFound(): void
	{
		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => 'nonexistent']);

		$this->assertSame(404, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertArrayHasKey('error', $data);
	}

	public function testReturns400WhenIdIsEmptyString(): void
	{
		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => '']);

		$this->assertSame(400, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertArrayHasKey('error', $data);
	}

	public function testCascadeDeletesGrantsForClientBeforeDeletingClient(): void
	{
		// Two clients
		$client1 = $this->makeClient('client-1', 'App One');
		$client2 = $this->makeClient('client-2', 'App Two');
		$this->clients->save($client1);
		$this->clients->save($client2);

		// Two grants for client-1, one for client-2
		$grant1 = $this->makeGrant('grant-a', 'client-1');
		$grant2 = $this->makeGrant('grant-b', 'client-1');
		$grant3 = $this->makeGrant('grant-c', 'client-2');
		$this->grants->save($grant1);
		$this->grants->save($grant2);
		$this->grants->save($grant3);

		$response = $this->makeSlimResponse();
		($this->action)($this->request, $response, ['id' => 'client-1']);

		// client-1 and its grants are gone
		$this->assertNull($this->clients->find('client-1'));
		$this->assertSame([], $this->grants->findByClientId('client-1'));

		// client-2 and its grant are untouched
		$this->assertNotNull($this->clients->find('client-2'));
		$remaining = $this->grants->findByClientId('client-2');
		$this->assertCount(1, $remaining);
		$this->assertSame('grant-c', $remaining[0]->id);
	}
}
