<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;

// `cms.form.loginForm()` always rendered an icon slot beside the email and
// password inputs. A site that leaves core's icon set out of its front end
// (frontendAssets except: icons) got two empty squares, with no switch to
// turn them off — the form builder has had `fieldIcons` for that, the login
// form did not.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->forms = $this->app->getContainer()->get(TotalFormFactory::class);
});

it('renders an icon slot beside each field by default', function (): void {
	expect(substr_count($this->forms->loginForm(), 'form-group-icon'))->toBe(2);
});

it('leaves the icon slots out when fieldIcons is false', function (): void {
	$html = $this->forms->loginForm(['fieldIcons' => false]);

	expect($html)->not->toContain('form-group-icon');
	// The fields themselves are untouched.
	expect($html)->toContain('name="email"')->toContain('type="password"');
});
