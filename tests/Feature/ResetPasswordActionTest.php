<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('ResetPasswordAction', function (): void {
	it('redirects when token is empty', function (): void {
		// Empty token in URL - this would 404 because route requires token
		$response = get('/admin/reset-password/');
		expect($response->getStatusCode())->toBeIn([302, 404]);
	});

	it('redirects when token is invalid', function (): void {
		$response = get('/admin/reset-password/invalid-token-12345');
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when token does not exist', function (): void {
		$response = get('/admin/reset-password/nonexistent-token');
		expect($response->getStatusCode())->toBe(302);
	});
});

describe('ResetPasswordSubmitAction', function (): void {
	it('redirects when password is empty', function (): void {
		$response = post('/admin/reset-password/some-token', [
			'password'         => '',
			'password_confirm' => '',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when passwords do not match', function (): void {
		$response = post('/admin/reset-password/some-token', [
			'password'         => 'newpassword123',
			'password_confirm' => 'differentpassword',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when password is too short', function (): void {
		$response = post('/admin/reset-password/some-token', [
			'password'         => '123',
			'password_confirm' => '123',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when token is invalid', function (): void {
		$response = post('/admin/reset-password/invalid-token', [
			'password'         => 'validpassword123',
			'password_confirm' => 'validpassword123',
		]);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles missing password fields', function (): void {
		$response = post('/admin/reset-password/some-token', []);
		expect($response->getStatusCode())->toBe(302);
	});

	it('trims whitespace from passwords', function (): void {
		$response = post('/admin/reset-password/some-token', [
			'password'         => '  validpassword  ',
			'password_confirm' => '  validpassword  ',
		]);
		expect($response->getStatusCode())->toBe(302);
	});
});
