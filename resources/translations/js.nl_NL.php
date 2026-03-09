<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Weet u zeker dat u deze afbeelding wilt verwijderen?',
	'confirm.delete_file'          => 'Weet u zeker dat u dit bestand wilt verwijderen?',
	'confirm.delete_item'          => 'Weet u zeker dat u dit wilt verwijderen? Dit kan niet ongedaan worden gemaakt.',
	'confirm.delete_files'         => 'Weet u zeker dat u {count} bestanden wilt verwijderen? Dit kan niet ongedaan worden gemaakt.',
	'confirm.delete_folder_name'   => 'De ingevoerde mapnaam komt niet overeen. Verwijdering geannuleerd.',
	'confirm.image_in_use'         => 'Deze afbeelding wordt momenteel gebruikt in de inhoud. Verwijdering zal de verwijzing verbreken. Doorgaan?',
	'confirm.file_in_use'          => 'Dit bestand wordt momenteel gebruikt in de inhoud. Verwijdering zal de koppeling verbreken. Doorgaan?',
	'confirm.video_in_use'         => 'Dit {label} wordt momenteel gebruikt in de inhoud. Verwijdering zal de verwijzing verbreken. Doorgaan?',
	'confirm.delete_label'         => 'Dit {label} verwijderen?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'De uitgelichte status kon niet worden bijgewerkt. Er is een netwerk- of time-outfout opgetreden. Probeer het opnieuw.',
	'error.cache_clear'            => 'De cache kon niet worden gewist. Er is een netwerk- of time-outfout opgetreden. Probeer het opnieuw.',
	'error.delete_image'           => 'De afbeelding kon niet worden verwijderd. Er is een netwerk- of time-outfout opgetreden. Probeer het opnieuw.',
	'error.delete_file'            => 'Het bestand kon niet worden verwijderd. Er is een netwerk- of time-outfout opgetreden. Probeer het opnieuw.',
	'error.delete_label'           => '{label} kon niet worden verwijderd',
	'error.no_processed_images'    => 'Geen verwerkte afbeeldingen beschikbaar om te downloaden',
	'error.enter_definition'       => 'Voer eerst een Twig-definitie in',
	'error.testing_view'           => 'Fout bij het testen van de weergave: {message}',
];
