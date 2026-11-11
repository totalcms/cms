<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Czy na pewno chcesz usunąć ten obraz?',
	'confirm.delete_file'          => 'Czy na pewno chcesz usunąć ten plik?',
	'confirm.delete_item'          => 'Czy na pewno chcesz to usunąć? Tej operacji nie można cofnąć.',
	'confirm.delete_files'         => 'Czy na pewno chcesz usunąć pliki ({count})? Tej operacji nie można cofnąć.',
	'confirm.delete_folder_name'   => 'Wprowadzona nazwa folderu nie jest zgodna. Usuwanie anulowano.',
	'confirm.delete_folder'        => 'Czy na pewno chcesz usunąć ten folder i całą jego zawartość? Tej operacji nie można cofnąć. Wpisz nazwę folderu, aby potwierdzić: "{name}"',
	'confirm.image_in_use'         => 'Ten obraz jest obecnie używany w treści. Usunięcie go zerwie odwołanie. Kontynuować?',
	'confirm.file_in_use'          => 'Ten plik jest obecnie używany w treści. Usunięcie go zerwie link. Kontynuować?',
	'confirm.video_in_use'         => 'Element {label} jest obecnie używany w treści. Usunięcie go zerwie odwołanie. Kontynuować?',
	'confirm.delete_label'         => 'Usunąć: {label}?',

	// Confirm dialog buttons — defaults for every tcmsConfirm() call
	'confirm.yes_sure'             => 'Tak, potwierdzam',
	'confirm.cancel'               => 'Anuluj',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Nie udało się zaktualizować statusu wyróżnienia. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.cache_clear'            => 'Nie udało się wyczyścić cache. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_image'           => 'Nie udało się usunąć obrazu. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_file'            => 'Nie udało się usunąć pliku. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.rename_folder'          => 'Nie udało się zmienić nazwy folderu. Wystąpił błąd sieci lub przekroczono limit czasu. Spróbuj ponownie.',
	'error.delete_label'           => 'Nie udało się usunąć: {label}',
	'error.no_processed_images'    => 'Brak przetworzonych obrazów do pobrania',
	'error.enter_definition'       => 'Najpierw wprowadź definicję Twig',
	'error.testing_view'           => 'Błąd podczas testowania widoku: {message}',

	// ── Depot Field ─────────────────────────────────────────────────────────
	'depot.processing'               => 'Przetwarzanie...',
	'depot.files_sent'               => 'Twoje pliki zostały wysłane.',
	'depot.file_too_big'             => 'Ten plik jest zbyt duży ({{filesize}} MB). Limit wynosi {{maxFilesize}} MB.',
	'depot.invalid_file_type'        => 'Ten typ pliku nie jest akceptowany.',
	'depot.upload_failed'            => 'Przesyłanie się nie powiodło. Spróbuj ponownie.',
	'depot.upload_expired'           => 'Ten formularz wygasł. Odśwież stronę i spróbuj ponownie.',
	'depot.remove_file'              => 'Usuń',

	// ── Markdown Field ──────────────────────────────────────────────────────
	'markdown.visual_title'        => 'Przełączyć na edytor wizualny?',
	'markdown.visual_lossy'        => 'Ta treść używa: {syntax}. Edytor wizualny nie może tego zachować; edycja w trybie wizualnym to usunie.',
	'markdown.visual_confirm'      => 'Przełącz mimo to',
	'markdown.source_notice'       => 'Otwarto w trybie źródłowym, ponieważ ta treść używa: {syntax}, czego edytor wizualny nie może zachować.',
	'markdown.syntax.html'         => 'surowy HTML',
	'markdown.syntax.comment'      => 'komentarze HTML',
	'markdown.syntax.footnote'     => 'przypisy',
	'markdown.syntax.abbreviation' => 'skróty',
	'markdown.syntax.linkedImage'  => 'obrazy z linkiem',

	// ── Form messages (public forms) ────────────────────────────────────────
	'form.fix_errors'           => 'Popraw błędy walidacji przed zapisaniem.',
	'form.has_errors'           => 'Błąd w formularzu, nie można zapisać.',
	'form.cannot_save'          => 'Nie można zapisać: {reason}',
	'form.action_failed'        => 'Wykonanie akcji nie powiodło się',
	'form.upload_failed'        => 'Przesyłanie nie powiodło się. Sprawdź logi serwera, aby poznać szczegóły.',
	'form.unknown_error'        => 'Nieznany błąd: {response}',
	'form.required_file'        => 'Pole {label} jest wymagane - prześlij plik',
	'form.required_image'       => 'Pole {label} jest wymagane - prześlij obraz',
	'form.required_images'      => 'Pole {label} jest wymagane - prześlij co najmniej jeden obraz',
	'form.select_one_or_more'   => 'Wybierz co najmniej jedną opcję.',
	'form.select_option'        => 'Wybierz opcję.',
	'form.clear_selection'      => 'Wyczyść wybór',
	'form.must_be_checked'      => 'To pole musi być zaznaczone.',
	'form.passwords_mismatch'   => 'Hasła nie są takie same.',
	'form.list_required'        => 'Dodaj do listy co najmniej jeden element.',
	'form.deck_required'        => 'Dodaj co najmniej jeden.',
	'form.min_items'            => 'Dodaj co najmniej {count} elementów',
	'form.max_items'            => 'Dozwolone maksymalnie {count} elementów',
	'form.duplicate_id'         => 'Znaleziono zduplikowany identyfikator: {id}',
	'form.item_id_empty'        => 'Identyfikator elementu nie może być pusty',
	'form.invalid_json'         => 'Nieprawidłowy format JSON.',
	'form.id_exists'            => 'Ten identyfikator już istnieje',
];
