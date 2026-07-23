<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Czy na pewno chcesz usunąć ten obraz?',
	'confirm.delete_file'          => 'Czy na pewno chcesz usunąć ten plik?',
	'confirm.delete_item'          => 'Czy na pewno chcesz to usunąć? Tej operacji nie można cofnąć.',
	'confirm.delete_files'         => 'Czy na pewno chcesz usunąć pliki ({count})? Tej operacji nie można cofnąć.',
	'confirm.delete_folder_name'   => 'Wprowadzona nazwa folderu nie jest zgodna. Usuwanie anulowano.',
	'confirm.image_in_use'         => 'Ten obraz jest obecnie używany w treści. Usunięcie go zerwie odwołanie. Kontynuować?',
	'confirm.file_in_use'          => 'Ten plik jest obecnie używany w treści. Usunięcie go zerwie link. Kontynuować?',
	'confirm.video_in_use'         => 'Element {label} jest obecnie używany w treści. Usunięcie go zerwie odwołanie. Kontynuować?',
	'confirm.delete_label'         => 'Usunąć: {label}?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Nie udało się zaktualizować statusu wyróżnienia. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.cache_clear'            => 'Nie udało się wyczyścić cache. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_image'           => 'Nie udało się usunąć obrazu. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_file'            => 'Nie udało się usunąć pliku. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_label'           => 'Nie udało się usunąć: {label}',
	'error.no_processed_images'    => 'Brak przetworzonych obrazów do pobrania',
	'error.enter_definition'       => 'Najpierw wprowadź definicję Twig',
	'error.testing_view'           => 'Błąd podczas testowania widoku: {message}',
];
