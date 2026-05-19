<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Sei sicuro di voler eliminare questa immagine?',
	'confirm.delete_file'          => 'Sei sicuro di voler eliminare questo file?',
	'confirm.delete_item'          => 'Sei sicuro di voler eliminare questo elemento? Questa azione non può essere annullata.',
	'confirm.delete_files'         => 'Sei sicuro di voler eliminare {count} file? Questa azione non può essere annullata.',
	'confirm.delete_folder_name'   => 'Il nome della cartella inserito non corrisponde. Eliminazione annullata.',
	'confirm.image_in_use'         => 'Questa immagine è attualmente utilizzata nel contenuto. Eliminandola si interromperà il riferimento. Continuare?',
	'confirm.file_in_use'          => 'Questo file è attualmente referenziato nel contenuto. Eliminandolo si interromperà il link. Continuare?',
	'confirm.video_in_use'         => 'Questo {label} è attualmente utilizzato nel contenuto. Eliminandolo si interromperà il riferimento. Continuare?',
	'confirm.delete_label'         => 'Eliminare questo {label}?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Aggiornamento dello stato in evidenza non riuscito. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.cache_clear'            => 'Cancellazione della cache non riuscita. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.delete_image'           => "Eliminazione dell'immagine non riuscita. Si è verificato un errore di rete o di timeout. Riprova.",
	'error.delete_file'            => 'Eliminazione del file non riuscita. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.delete_label'           => 'Eliminazione di {label} non riuscita',
	'error.no_processed_images'    => 'Nessuna immagine elaborata da scaricare',
	'error.enter_definition'       => 'Inserisci prima una definizione Twig',
	'error.testing_view'           => 'Errore nel test della vista: {message}',
];
