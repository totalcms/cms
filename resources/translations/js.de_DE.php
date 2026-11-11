<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Sind Sie sicher, dass Sie dieses Bild löschen möchten?',
	'confirm.delete_file'          => 'Sind Sie sicher, dass Sie diese Datei löschen möchten?',
	'confirm.delete_item'          => 'Sind Sie sicher, dass Sie dies löschen möchten? Dies kann nicht rückgängig gemacht werden.',
	'confirm.delete_files'         => 'Sind Sie sicher, dass Sie {count} Dateien löschen möchten? Dies kann nicht rückgängig gemacht werden.',
	'confirm.delete_folder_name'   => 'Der eingegebene Ordnername stimmt nicht überein. Löschung abgebrochen.',
	'confirm.delete_folder'        => 'Sind Sie sicher, dass Sie diesen Ordner und seinen gesamten Inhalt löschen möchten? Dies kann nicht rückgängig gemacht werden. Geben Sie zur Bestätigung den Ordnernamen ein: "{name}"',
	'confirm.image_in_use'         => 'Dieses Bild wird derzeit im Inhalt verwendet. Durch das Löschen wird die Referenz ungültig. Fortfahren?',
	'confirm.file_in_use'          => 'Diese Datei wird derzeit im Inhalt referenziert. Durch das Löschen wird der Link ungültig. Fortfahren?',
	'confirm.video_in_use'         => 'Dieses {label} wird derzeit im Inhalt verwendet. Durch das Löschen wird die Referenz ungültig. Fortfahren?',
	'confirm.delete_label'         => 'Dieses {label} löschen?',

	// Confirm dialog buttons — defaults for every tcmsConfirm() call
	'confirm.yes_sure'             => 'Ja, ich bin sicher',
	'confirm.cancel'               => 'Abbrechen',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Der Hervorgehoben-Status konnte nicht aktualisiert werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.cache_clear'            => 'Der Cache konnte nicht geleert werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_image'           => 'Das Bild konnte nicht gelöscht werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_file'            => 'Die Datei konnte nicht gelöscht werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.rename_folder'          => 'Der Ordner konnte nicht umbenannt werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_label'           => '{label} konnte nicht gelöscht werden',
	'error.no_processed_images'    => 'Keine verarbeiteten Bilder zum Herunterladen vorhanden',
	'error.enter_definition'       => 'Bitte geben Sie zuerst eine Twig-Definition ein',
	'error.testing_view'           => 'Fehler beim Testen der Ansicht: {message}',

	// ── Depot Field ─────────────────────────────────────────────────────────
	'depot.processing'               => 'Wird verarbeitet...',
	'depot.files_sent'               => 'Ihre Dateien wurden gesendet.',
	'depot.file_too_big'             => 'Diese Datei ist zu groß ({{filesize}} MB). Das Limit beträgt {{maxFilesize}} MB.',
	'depot.invalid_file_type'        => 'Dieser Dateityp wird nicht akzeptiert.',
	'depot.upload_failed'            => 'Der Upload hat nicht geklappt. Bitte versuchen Sie es erneut.',
	'depot.upload_expired'           => 'Dieses Formular ist abgelaufen. Laden Sie die Seite neu und versuchen Sie es erneut.',
	'depot.remove_file'              => 'Entfernen',

	// ── Markdown Field ──────────────────────────────────────────────────────
	'markdown.visual_title'        => 'In den visuellen Editor wechseln?',
	'markdown.visual_lossy'        => 'Dieser Inhalt verwendet {syntax}. Der visuelle Editor kann das nicht erhalten; beim Bearbeiten im visuellen Modus geht es verloren.',
	'markdown.visual_confirm'      => 'Trotzdem wechseln',
	'markdown.source_notice'       => 'Im Quelltextmodus geöffnet, weil dieser Inhalt {syntax} verwendet, was der visuelle Editor nicht erhalten kann.',
	'markdown.syntax.html'         => 'rohes HTML',
	'markdown.syntax.comment'      => 'HTML-Kommentare',
	'markdown.syntax.footnote'     => 'Fußnoten',
	'markdown.syntax.abbreviation' => 'Abkürzungen',
	'markdown.syntax.linkedImage'  => 'verlinkte Bilder',

	// ── Form messages (public forms) ────────────────────────────────────────
	'form.fix_errors'       => 'Bitte beheben Sie die Validierungsfehler vor dem Speichern.',
	'form.has_errors'       => 'Fehler im Formular, Speichern nicht möglich.',
	'form.cannot_save'      => 'Speichern nicht möglich: {reason}',
	'form.action_failed'    => 'Aktion konnte nicht ausgeführt werden',
	'form.upload_failed'    => 'Upload fehlgeschlagen. Bitte prüfen Sie die Server-Logs.',
	'form.unknown_error'    => 'Unbekannter Fehler: {response}',
	'form.required_file'        => '{label} ist erforderlich - bitte laden Sie eine Datei hoch',
	'form.required_image'       => '{label} ist erforderlich - bitte laden Sie ein Bild hoch',
	'form.required_images'      => '{label} ist erforderlich - bitte laden Sie mindestens ein Bild hoch',
	'form.select_one_or_more'   => 'Bitte wählen Sie mindestens eine Option aus.',
	'form.select_option'        => 'Bitte wählen Sie eine Option aus.',
	'form.must_be_checked'      => 'Dieses Feld muss angehakt werden.',
	'form.passwords_mismatch'   => 'Die Passwörter stimmen nicht überein.',
	'form.list_required'        => 'Bitte fügen Sie der Liste mindestens einen Eintrag hinzu.',
	'form.deck_required'        => 'Bitte fügen Sie mindestens einen hinzu.',
	'form.min_items'            => 'Bitte fügen Sie mindestens {count} Einträge hinzu',
	'form.max_items'            => 'Höchstens {count} Einträge erlaubt',
	'form.duplicate_id'         => 'Doppelte ID gefunden: {id}',
	'form.item_id_empty'        => 'Die Eintrags-ID darf nicht leer sein',
	'form.invalid_json'         => 'Ungültiges JSON-Format.',
	'form.id_exists'            => 'Diese ID existiert bereits',
];
