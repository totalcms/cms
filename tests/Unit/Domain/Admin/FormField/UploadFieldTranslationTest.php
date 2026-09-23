<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\DepotField;
use TotalCMS\Domain\Admin\FormField\FileField;
use TotalCMS\Domain\Admin\FormField\GalleryField;
use TotalCMS\Domain\Admin\FormField\ImageField;
use TotalCMS\Domain\Admin\FormField\UploadField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * The four Dropzone-backed fields share one UploadField base, so the file and
 * image dialogs pick up the admin catalog the same way the depot dialog does.
 * The form is mocked so subField() records the options each section asks
 * for; the translator is real.
 */
function uploadFieldForm(string $locale, array &$subFields): TotalForm
{
	$configRc = new ReflectionClass(Config::class);
	$config   = $configRc->newInstanceWithoutConstructor();
	$configRc->getProperty('locale')->setValue($config, $locale);
	$service = new TranslationService($config, dirname(__DIR__, 5) . '/resources/translations');

	$realRc = new ReflectionClass(TotalForm::class);
	$real   = $realRc->newInstanceWithoutConstructor();
	$realRc->getProperty('translator')->setValue($real, $service->trans(...));

	$form             = test()->createMock(TotalForm::class);
	$form->id         = '';
	$form->collection = 'test-collection';
	$form->api        = '/api';
	$form->method('isEditMode')->willReturn(false);
	$form->method('isPropertyIndexed')->willReturn(false);
	$form->method('baseApi')->willReturn('/api');
	$form->method('field')->willReturn('');
	$form->method('subField')->willReturnCallback(function (string $name, array $options) use (&$subFields): string {
		$subFields[$name] = $options;

		return '';
	});
	$form->method('t')->willReturnCallback(
		fn (string $key, string $default = '', array $params = []): string => $real->t($key, $default, $params)
	);

	return $form;
}

test('the four upload fields share one base', function (): void {
	foreach ([FileField::class, ImageField::class, GalleryField::class, DepotField::class] as $class) {
		expect(is_subclass_of($class, UploadField::class))->toBeTrue("$class extends UploadField");
	}
});

test('the file dialog is translated like the depot dialog', function (): void {
	$subFields = [];
	$html      = (new FileField(form: uploadFieldForm('de_DE', $subFields), name: 'myfile', value: []))->buildFormField();

	expect($html)->toContain('title="Dateiinformationen bearbeiten"')
		->and($html)->toContain('title="Datei löschen"')
		->and($html)->toContain('>Schließen<')
		->and($html)->not->toContain('Edit File Info')
		->and($subFields['download']['label'])->toBe('Download-Name')
		->and($subFields['password']['help'])->not->toContain('Require a password')
		->and($subFields['uploadDate']['label'])->toBe('Hochladedatum');
});

test('the image dialog is translated too', function (): void {
	$subFields = [];
	$html      = (new ImageField(form: uploadFieldForm('de_DE', $subFields), name: 'myimage', value: []))->buildFormField();

	expect($html)->not->toContain('Edit Image Info')
		->and($html)->toContain('title="Bildinformationen bearbeiten"')
		->and($subFields['alt']['label'])->toBe('Alternativtext')
		->and($subFields['exif-author']['placeholder'])->not->toContain('Autor Found')
		->and($subFields['height']['label'])->toBe('Höhe');
});

test('English defaults hold without a translator', function (): void {
	$subFields = [];
	$form      = uploadFieldForm('en_US', $subFields);
	$html      = (new FileField(form: $form, name: 'myfile', value: []))->buildFormField();

	expect($html)->toContain('title="Edit File Info"')
		->and($html)->toContain('title="Upload New File"')
		->and($subFields['download']['help'])->toBe('The name of the file when it gets downloaded.');
});

test('the link dialog drops empty query parts for every upload field', function (): void {
	$subFields = [];
	$form      = uploadFieldForm('en_US', $subFields);

	$file  = (new FileField(form: $form, name: 'myfile', value: []))->buildFormField();
	$image = (new ImageField(form: $form, name: 'myimage', value: []))->buildFormField();

	expect($file)->toContain('/api/admin/filelinks?collection=test-collection&amp;property=myfile')
		->and($image)->toContain('/api/admin/imageworks?collection=test-collection&amp;property=myimage');
});
