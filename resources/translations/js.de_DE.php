<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Sind Sie sicher, dass Sie dieses Bild löschen möchten?',
	'confirm.delete_file'          => 'Sind Sie sicher, dass Sie diese Datei löschen möchten?',
	'confirm.delete_item'          => 'Sind Sie sicher, dass Sie dies löschen möchten? Dies kann nicht rückgängig gemacht werden.',
	'confirm.delete_files'         => 'Sind Sie sicher, dass Sie {count} Dateien löschen möchten? Dies kann nicht rückgängig gemacht werden.',
	'confirm.delete_folder_name'   => 'Der eingegebene Ordnername stimmt nicht überein. Löschung abgebrochen.',
	'confirm.image_in_use'         => 'Dieses Bild wird derzeit im Inhalt verwendet. Durch das Löschen wird die Referenz ungültig. Fortfahren?',
	'confirm.file_in_use'          => 'Diese Datei wird derzeit im Inhalt referenziert. Durch das Löschen wird der Link ungültig. Fortfahren?',
	'confirm.video_in_use'         => 'Dieses {label} wird derzeit im Inhalt verwendet. Durch das Löschen wird die Referenz ungültig. Fortfahren?',
	'confirm.delete_label'         => 'Dieses {label} löschen?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Der Hervorgehoben-Status konnte nicht aktualisiert werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.cache_clear'            => 'Der Cache konnte nicht geleert werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_image'           => 'Das Bild konnte nicht gelöscht werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_file'            => 'Die Datei konnte nicht gelöscht werden. Ein Netzwerk- oder Zeitüberschreitungsfehler ist aufgetreten. Bitte versuchen Sie es erneut.',
	'error.delete_label'           => '{label} konnte nicht gelöscht werden',
	'error.no_processed_images'    => 'Keine verarbeiteten Bilder zum Herunterladen vorhanden',
	'error.enter_definition'       => 'Bitte geben Sie zuerst eine Twig-Definition ein',
	'error.testing_view'           => 'Fehler beim Testen der Ansicht: {message}',
];
