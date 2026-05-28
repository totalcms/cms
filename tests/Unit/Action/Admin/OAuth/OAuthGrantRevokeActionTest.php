<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\OAuth;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\OAuth\OAuthGrantRevokeAction;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Renderer\JsonRenderer;

final class OAuthGrantRevokeActionTest extends TestCase
{
	private OAuthGrantRevokeAction $action;
	private OAuthGrantRepository $grants;
	private JsonRenderer $jsonRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private string $grantsTmpFile;

	protected function setUp(): void
	{
		$this->grantsTmpFile = sys_get_temp_dir() . '/oauth-grants-revoke-test-' . uniqid() . '.json';
		$this->grants        = new OAuthGrantRepository($this->grantsTmpFile);
		$this->jsonRenderer  = new JsonRenderer();
		$this->request       = $this->createMock(ServerRequestInterface::class);

		$this->action = new OAuthGrantRevokeAction(
			$this->grants,
			$this->jsonRenderer,
		);
	}

	protected function tearDown(): void
	{
		if (is_file($this->grantsTmpFile)) {
			unlink($this->grantsTmpFile);
		}
	}

	private function makeGrant(string $id): OAuthGrantData
	{
		return new OAuthGrantData(
			id: $id,
			clientId: 'client-1',
			userId: 'user@example.com',
			scopes: ['cms:read'],
			refreshTokenHash: 'hash-' . $id,
			issuedAt: '2026-01-01T00:00:00Z',
			expiresAt: '2027-01-01T00:00:00Z',
		);
	}

	private function makeSlimResponse(): ResponseInterface
	{
		return new \Slim\Psr7\Response();
	}

	public function testRevokesGrantSuccessfullyAndReturns200(): void
	{
		$grant = $this->makeGrant('grant-abc');
		$this->grants->save($grant);

		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => 'grant-abc']);

		$this->assertSame(200, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertTrue($data['success']);
		// Verify the grant was actually removed
		$this->assertNull($this->grants->find('grant-abc'));
	}

	public function testReturns404WhenGrantNotFound(): void
	{
		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => 'nonexistent-grant']);

		$this->assertSame(404, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertArrayHasKey('error', $data);
	}

	public function testReturns400WhenIdIsEmpty(): void
	{
		$response = $this->makeSlimResponse();
		$result   = ($this->action)($this->request, $response, ['id' => '']);

		$this->assertSame(400, $result->getStatusCode());
		$body = (string)$result->getBody();
		/** @var array<string,mixed> $data */
		$data = json_decode($body, true);
		$this->assertArrayHasKey('error', $data);
	}
}
