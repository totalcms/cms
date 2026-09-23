<?php

declare(strict_types=1);

namespace Tests\Unit\JavaScript;

use PHPUnit\Framework\TestCase;

/**
 * The image meta dialog's Discard Changes (issue #111). The hard part was never
 * skipping the close-time autosave — it was that an abandoned edit left in
 * the dialog's fields would be swept up by a later form save. So Cancel
 * restores every dialog field to its opening snapshot AS SAVED, and the
 * autosave / commit on close then find nothing to do. Pinned at the source
 * level, the way the other JavaScript tests here do.
 */
final class ImageDialogCancelTest extends TestCase
{
	private function src(string $path): string
	{
		return (string)file_get_contents(__DIR__ . '/../../../' . $path);
	}

	public function testTheDialogRendersSaveThenDiscardInOneActionsRow(): void
	{
		$php  = $this->src('src/Domain/Admin/FormField/ImageField.php');
		$body = (string)preg_replace('/.*(?:private|protected) function closeSection\(\): string/s', '', $php);
		$body = substr($body, 0, (int)strpos($body, "\n\t}"));

		// The class names are the contract: the JS binds `.cancel` and the Dialog closes on `.close`.
		$this->assertStringContainsString("\$this->t('btn.discard', 'Discard Changes'), ['class' => 'cancel cms-button transparent no-icon']", $body);
		$this->assertStringContainsString("\$this->t('btn.save', 'Save'), ['class' => 'close cms-button no-icon']", $body);
		$this->assertLessThan(strpos($body, '$save . $discard'), strpos($body, "'btn.discard'"), 'Save renders first, Discard second.');
		$this->assertStringContainsString("['class' => 'dialog-actions']", $body, 'The row is flex-laid by dialog.scss.');

		foreach (['en_US', 'en_GB', 'de_DE', 'es_ES', 'it_IT', 'nl_NL', 'pl_PL'] as $locale) {
			$this->assertStringContainsString("'btn.discard'", $this->src("resources/translations/admin.{$locale}.php"), $locale);
		}
	}

	public function testEscapeDiscardsBeforeTheDialogClosesButABackdropClickSaves(): void
	{
		$dialog = $this->src('javascript/totalform/dialog.js');

		// The native cancel event (Escape) goes through dismissed() ahead of onClose; the backdrop hit-test is a plain close.
		$this->assertMatchesRegularExpression('/addEventListener\(\'cancel\', \(\) => \{.*?this\.dismissed\(\);.*?this\.options\.onClose/s', $dialog);
		$this->assertMatchesRegularExpression('/if \(!isInDialog\) \{\s*this\.close\(\);/', $dialog);
		$this->assertSame(1, substr_count($dialog, 'this.dismissed();'), 'Only Escape dismisses.');

		$this->assertStringContainsString('onDismiss : () => this.restoreDialogSnapshot()', $this->src('javascript/totalform/image-preview.js'));
		$this->assertStringContainsString('onDismiss : discardSharedDialog', $this->src('javascript/totalform/gallery.js'));
	}

	public function testTheImageDialogSnapshotsEveryFieldOnEachOpen(): void
	{
		$js = $this->src('javascript/totalform/image-preview.js');

		// The one raw open() lives inside openEditDialog(); every other caller must go through it so a snapshot is taken.
		$this->assertSame(1, substr_count($js, 'this.editDialog.open();'));
		$this->assertMatchesRegularExpression('/openEditDialog\(\) \{\s*this\.dialogSnapshot = new Map\(.*?getValue\(\)\]\)\);\s*this\.editDialog\.open\(\);/s', $js);
	}

	public function testImageCancelRestoresTheSnapshotAsSavedThenCloses(): void
	{
		$js   = $this->src('javascript/totalform/image-preview.js');
		$body = (string)preg_replace('/.*restoreDialogSnapshot\(\) \{/s', '', $js);
		$body = substr($body, 0, (int)strpos($body, "\n\t}"));

		$this->assertStringContainsString('field.totalfield.setSavedValue(value)', $body);
		$this->assertStringNotContainsString('.setValue(', $body, 'setValue() would mark the restored fields unsaved.');
		$this->assertStringContainsString('this.totalfield.saved()', $body);
		// The button restores first, closes second — close triggers the autosave.
		$this->assertMatchesRegularExpression('/querySelector\("\.cancel"\).*?restoreDialogSnapshot\(\);\s*this\.editDialog\.close\(\);/s', $js);
	}

	public function testGalleryCancelRepopulatesFromTheOpeningSnapshotThenCloses(): void
	{
		$js = $this->src('javascript/totalform/gallery.js');

		$this->assertMatchesRegularExpression('/this\.sharedDialogSnapshot = imageData;\s*this\.populateSharedDialog\(imageData\);/', $js);
		$this->assertMatchesRegularExpression('/discardSharedDialog = \(\) => \{.*?populateSharedDialog\(this\.sharedDialogSnapshot\);/s', $js);
		$this->assertMatchesRegularExpression('/querySelector\("\.cancel"\).*?discardSharedDialog\(\);\s*this\.sharedDialog\.close\(\);/s', $js);
	}
}
