<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;

// `cms.form.loginForm()` always rendered the "Keep me signed in" checkbox. A
// site that does not want to offer the 30-day persistent login had no way to
// leave it off the form.

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$this->forms = $this->app->getContainer()->get(TotalFormFactory::class);
});

it('shows the remember-me checkbox by default', function (): void {
	expect($this->forms->loginForm())->toContain('name="persistent_login"');
});

it('leaves the remember-me checkbox out when showRememberMe is false', function (): void {
	$html = $this->forms->loginForm(['showRememberMe' => false]);

	expect($html)->not->toContain('persistent_login');
	// The rest of the form is untouched.
	expect($html)->toContain('type="password"')->toContain('type="submit"');
});
