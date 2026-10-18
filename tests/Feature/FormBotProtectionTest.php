<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\BotProtection;

/**
 * The form builder's `botProtection` option: a honeypot and a signed
 * time-trap token on anonymous create and register forms, and nothing on
 * edit forms or for a signed-in visitor.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());

	$c     = $this->app->getContainer();
	$saver = $c->get(CollectionSaver::class);
	$saver->saveCollection(['id' => 'notes', 'name' => 'Notes', 'schema' => 'text']);
	$saver->saveCollection(['id' => 'guarded', 'name' => 'Guarded', 'schema' => 'text', 'publicOperations' => ['create'], 'requireBotProtection' => true]);
	$saver->saveCollection(['id' => 'guarded-private', 'name' => 'Guarded Private', 'schema' => 'text', 'requireBotProtection' => true]);
	$c->get(ObjectSaver::class)->saveObject('notes', ['id' => 'one', 'text' => 'One']);

	$this->factory = $c->get(TotalFormFactory::class);
});

/** The time-trap token a rendered form carries, or '' when it has none. */
function botToken(string $html): string
{
	return preg_match('/name="' . BotProtection::TIMESTAMP_FIELD . '"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="' . BotProtection::TIMESTAMP_FIELD . '"/', $html, $m) === 1
		? ($m[1] !== '' ? $m[1] : $m[2])
		: '';
}

function hasBotFields(string $html): bool
{
	return str_contains($html, 'name="' . BotProtection::HONEYPOT_FIELD . '"') || str_contains($html, 'name="' . BotProtection::TIMESTAMP_FIELD . '"');
}

it('renders the honeypot and a create token on an add-only form with botProtection', function (): void {
	$html = $this->factory->builder('notes', ['addOnly' => true, 'botProtection' => true])->autoBuild();

	expect($html)->toMatch('/<div class="form-field text-field bot-field" data-type="text" aria-hidden="true"><label for="field-[0-9a-f]+">Leave this field empty<\/label><div class="form-group"><input type="text" id="field-[0-9a-f]+" name="extra_reference_code" tabindex="-1" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"\/><\/div><\/div>/');

	$token = botToken($html);
	expect($token)->not->toBe('');
	$body = [BotProtection::HONEYPOT_FIELD => '', BotProtection::TIMESTAMP_FIELD => $token];
	expect((new BotProtection())->check($body, 'notes', 'create', time() + 10))->toBe('');
	expect((new BotProtection())->check($body, 'notes', 'register', time() + 10))->toBe('bad_signature');
});

it('signs a register form for register', function (): void {
	$html = $this->factory->builder('notes', ['register' => true, 'botProtection' => true])->autoBuild();

	$body = [BotProtection::TIMESTAMP_FIELD => botToken($html)];
	expect((new BotProtection())->check($body, 'notes', 'register', time() + 10))->toBe('');
});

it('renders neither field without botProtection', function (): void {
	expect(hasBotFields($this->factory->builder('notes')->autoBuild()))->toBeFalse();
	expect(hasBotFields($this->factory->builder('notes', ['register' => true])->autoBuild()))->toBeFalse();
});

it('renders neither field on an edit form', function (): void {
	expect(hasBotFields($this->factory->builder('notes', ['id' => 'one', 'botProtection' => true])->autoBuild()))->toBeFalse();
});

it('renders neither field for a signed-in visitor', function (): void {
	signInAs($this->app, 'admin-user-test-com');

	expect(hasBotFields($this->factory->builder('notes', ['addOnly' => true, 'botProtection' => true])->autoBuild()))->toBeFalse();
	expect(hasBotFields($this->factory->builder('guarded', ['addOnly' => true])->autoBuild()))->toBeFalse();
});

it('adds the fields without the option where the collection requires them', function (): void {
	// Public create + Require Bot Protection: any new-object form (plain or
	// add-only), and the register form.
	expect(hasBotFields($this->factory->builder('guarded')->autoBuild()))->toBeTrue();
	expect(hasBotFields($this->factory->builder('guarded', ['addOnly' => true])->autoBuild()))->toBeTrue();
	expect(hasBotFields($this->factory->builder('guarded-private', ['register' => true])->autoBuild()))->toBeTrue();

	// No public create to protect.
	expect(hasBotFields($this->factory->builder('guarded-private')->autoBuild()))->toBeFalse();
	expect(hasBotFields($this->factory->builder('guarded-private', ['addOnly' => true])->autoBuild()))->toBeFalse();
});
