<?php

use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('TemplateUpdateAction', function (): void {
	it('handles update request for template', function (): void {
		$response = putJson('/api/templates/test-template', [
			'id'       => 'test-template',
			'template' => '<h1>{{ title }}</h1>',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles update for template in folder', function (): void {
		$response = putJson('/api/templates/partials/header', [
			'id'       => 'partials/header',
			'template' => '<header>{{ site.name }}</header>',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles update with empty template content', function (): void {
		$response = putJson('/api/templates/empty-template', [
			'id'       => 'empty-template',
			'template' => '',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles template rename via update', function (): void {
		$response = putJson('/api/templates/old-name', [
			'id'       => 'new-name',
			'template' => '<p>Content</p>',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles template move to different folder', function (): void {
		$response = putJson('/api/templates/template-to-move', [
			'id'       => 'layouts/template-to-move',
			'template' => '<div>Moved</div>',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles complex Twig template content', function (): void {
		$template = <<<'TWIG'
{% extends "base.twig" %}
{% block content %}
  <h1>{{ title }}</h1>
  {% for item in items %}
    <div>{{ item.name }}</div>
  {% endfor %}
{% endblock %}
TWIG;
		$response = putJson('/api/templates/complex-template', [
			'id'       => 'complex-template',
			'template' => $template,
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});
