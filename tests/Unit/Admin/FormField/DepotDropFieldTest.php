<?php

use TotalCMS\Domain\Admin\FormField\DepotDropField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Security\BotProtection;

describe('DepotDropField', function (): void {
	beforeEach(function (): void {
		$this->form     = $this->createMock(TotalForm::class);
		$this->form->id = 'test-id';

		// createMock() stubs t() to return '', which is not how the real method
		// behaves: TotalForm::t() falls back to the English default whenever no
		// translator closure was injected. Without this the mock renders empty
		// attributes for every translated string and the assertions below test
		// the mock rather than the field.
		$this->form->method('t')->willReturnCallback(
			static fn (string $key, string $default = '', array $params = []): string => $default !== '' ? $default : $key,
		);
	});

	test('DepotDropField → buildFormField returns HTML with input', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('<input');
		expect($html)->toContain('type="text"');
		expect($html)->toContain('name="files"');
	});

	test('DepotDropField → buildFormField includes dropzone', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('depot-drop-zone');
		expect($html)->toContain('dz-clickable');
	});

	test('DepotDropField → buildFormField includes overlay', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('dz-overlay');
	});

	test('DepotDropField → buildFormField includes preview list', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('total-preview');
		expect($html)->toContain('<ul');
	});

	test('DepotDropField → buildFormField includes upload button', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('depot-drop-upload');
		expect($html)->toContain('<button');
		expect($html)->toContain('Upload Files');
	});

	test('DepotDropField → buildFormField includes file template', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('<template');
		expect($html)->toContain('file-template');
	});

	test('DepotDropField → file template has correct structure', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->buildFormField();

		expect($html)->toContain('file-icon');
		expect($html)->toContain('filename');
		expect($html)->toContain('dz-preview');
		expect($html)->toContain('depot-drop-card');
	});

	test('DepotDropField → uses uuid for field id', function (): void {
		$field = new DepotDropField($this->form, 'my-files');

		$html = $field->buildFormField();

		expect($html)->toContain('id="field-');
	});

	test('DepotDropField → build returns full field structure', function (): void {
		$field = new DepotDropField($this->form, 'files');

		$html = $field->build();

		// The build() method should include the field wrapper
		expect($html)->toContain('form-group');
		expect($html)->toContain('data-type="depotDrop"');
	});

	test('DepotDropField → comments off renders neither the box nor the button', function (): void {
		$html = (new DepotDropField($this->form, 'files'))->build();

		expect($html)->not->toContain('<textarea');
		expect($html)->not->toContain('depot-drop-send');
		expect($html)->not->toContain('data-settings');
	});

	test('DepotDropField → the comment box grows with its text, from three rows', function (): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['comments' => true]))->build();

		expect($html)->toContain('data-autosize')
			->and($html)->toContain('--rows:3');
	});

	test('DepotDropField → comments setting renders the textarea and send button for a signed-in user', function (): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['comments' => true]))->build();

		expect($html)->toContain('<textarea');
		expect($html)->toContain('maxlength="1000"');
		expect($html)->toContain('depot-drop-send cms-button no-icon');
		expect($html)->toContain('Comment');
		expect($html)->toContain('Send Files');
		// A signed-in visitor gets no bot fields.
		expect($html)->not->toContain(BotProtection::HONEYPOT_FIELD);
		expect($html)->not->toContain(BotProtection::TIMESTAMP_FIELD);
	});

	test('DepotDropField → tags render a one-choice menu and the send button, with no comment box', function (): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['tags' => ['Invoice', ['value' => 'x"y', 'label' => '<b>Other</b>'], '', 7]]))->build();

		expect($html)->toContain('depot-drop-tag');
		expect($html)->not->toContain(' multiple');
		expect($html)->toContain('<option value></option>');
		expect($html)->toContain('<option value="Invoice">Invoice</option>');
		expect($html)->toContain('value="x&quot;y"');
		expect($html)->toContain('&lt;b&gt;Other&lt;/b&gt;');
		expect($html)->toContain('depot-drop-send');
		expect($html)->not->toContain('<textarea');
	});

	test('DepotDropField → no usable tags render no menu', function (mixed $tags): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['tags' => $tags]))->build();

		expect($html)->not->toContain('depot-drop-tag');
		expect($html)->not->toContain('depot-drop-send');
	})->with([[[]], ['Invoice'], [['', '  ']]]);

	test('DepotDropField → the help text stays with the drop zone, above the batch fields', function (): void {
		$html = (new DepotDropField($this->form, 'files', help: 'Drop your signed forms here.', settings: ['tags' => ['Invoice'], 'comments' => true]))->build();

		expect(strpos($html, 'depot-drop-zone'))->toBeLessThan(strpos($html, 'Drop your signed forms here.'));
		expect(strpos($html, 'Drop your signed forms here.'))->toBeLessThan(strpos($html, 'depot-drop-tag'));
		expect(strpos($html, 'depot-drop-tag'))->toBeLessThan(strpos($html, 'depot-drop-send'));
	});

	test('DepotDropField → the tag and comment labels can be replaced, and are escaped', function (): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['tags' => ['Invoice'], 'comments' => true, 'tagLabel' => 'Document <type>', 'commentLabel' => 'Notes']))->build();

		expect($html)->toContain('>Document &lt;type&gt;</label>');
		expect($html)->toContain('>Notes</label>');
		expect($html)->not->toContain('>Tag</label>');
		expect($html)->not->toContain('>Comment</label>');
	});

	test('DepotDropField → a blank label keeps the default', function (): void {
		$html = (new DepotDropField($this->form, 'files', settings: ['tags' => ['Invoice'], 'tagLabel' => '  ']))->build();

		expect($html)->toContain('>Tag</label>');
	});

	test('DepotDropField → the drop hint shows only when the field has no help text', function (): void {
		expect((new DepotDropField($this->form, 'files'))->build())->toContain('Drop files here or click to browse');
		expect((new DepotDropField($this->form, 'files', help: 'Signed forms only.'))->build())->not->toContain('depot-drop-hint');
	});

	test('DepotDropField → a template can hide a granted comment box but not add one', function (): void {
		$this->form->collection = 'docs';
		$this->form->method('publicUploadGrant')->willReturnOnConsecutiveCalls(
			new PublicUploadGrant(['pdf'], 1048576, 2097152, null, true),
			new PublicUploadGrant(['pdf'], 1048576, 2097152, null, false),
		);

		expect((new DepotDropField($this->form, 'files', settings: ['comments' => false]))->build())->not->toContain('<textarea');
		expect((new DepotDropField($this->form, 'files', settings: ['comments' => true]))->build())->not->toContain('<textarea');
	});

	test('DepotDropField → an anonymous visitor on a granted depot gets bot fields and an accept list', function (): void {
		$this->form->collection = 'docs';
		$this->form->method('publicUploadGrant')->willReturn(new PublicUploadGrant(['pdf', 'jpg'], 5 * 1024 * 1024, 50 * 1024 * 1024, null, false));

		$html = (new DepotDropField($this->form, 'files'))->build();

		expect($html)->toContain('name="' . BotProtection::HONEYPOT_FIELD . '"');
		expect($html)->toContain('name="' . BotProtection::TIMESTAMP_FIELD . '"');
		expect($html)->toContain('data-settings=');
		expect($html)->toContain('.pdf,.jpg');
		expect($html)->toContain('Processing...');
		expect($html)->toContain('Your files were sent.');
		expect($html)->not->toContain('<textarea');

		preg_match('/name="' . BotProtection::TIMESTAMP_FIELD . '"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="' . BotProtection::TIMESTAMP_FIELD . '"/', $html, $m);
		$token = $m[1] !== '' ? $m[1] : $m[2];
		expect((new BotProtection())->check([BotProtection::TIMESTAMP_FIELD => $token, BotProtection::HONEYPOT_FIELD => ''], 'docs', 'upload', time() + 10))->toBe('');
	});

	test('DepotDropField → a grant with comments on renders the comment box for an anonymous visitor', function (): void {
		$this->form->collection = 'docs';
		$this->form->method('publicUploadGrant')->willReturn(new PublicUploadGrant(['pdf'], 1048576, 2097152, null, true));

		$html = (new DepotDropField($this->form, 'files'))->build();

		expect($html)->toContain('<textarea');
		expect($html)->toContain('depot-drop-send');
	});

	test('DepotDropField → the grant never reaches the page, for a visitor or a signed-in user', function (): void {
		$this->form->collection = 'docs';
		$publicUpload           = ['extensions' => ['pdf'], 'folder' => 'incoming', 'maxTotalMb' => 777, 'comments' => true];

		// Anonymous: a grant with a folder, and the property settings carrying it.
		$this->form->method('publicUploadGrant')->willReturn(new PublicUploadGrant(['pdf'], 1048576, 777 * 1048576, 'incoming', true));
		$anonymous = (new DepotDropField($this->form, 'files', settings: ['publicUpload' => $publicUpload]))->build();

		// Signed in: no grant, but the same property settings.
		$form     = $this->createMock(TotalForm::class);
		$form->id = 'test-id';
		$form->method('t')->willReturnCallback(static fn (string $key, string $default = ''): string => $default !== '' ? $default : $key);
		$signedIn = (new DepotDropField($form, 'files', settings: ['publicUpload' => $publicUpload, 'comments' => true]))->build();

		foreach ([$anonymous, $signedIn] as $html) {
			expect($html)->not->toContain('incoming')
				->and($html)->not->toContain('publicUpload')
				->and($html)->not->toContain('maxTotalMb')
				// As a whole number: the field's random hex ids contain "777" now and then.
				->and($html)->not->toMatch('/\b777\b/');
		}
	});
});
