<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Sei sicuro di voler eliminare questa immagine?',
	'confirm.delete_file'          => 'Sei sicuro di voler eliminare questo file?',
	'confirm.delete_item'          => 'Sei sicuro di voler eliminare questo elemento? Questa azione non può essere annullata.',
	'confirm.delete_files'         => 'Sei sicuro di voler eliminare {count} file? Questa azione non può essere annullata.',
	'confirm.delete_folder_name'   => 'Il nome della cartella inserito non corrisponde. Eliminazione annullata.',
	'confirm.delete_folder'        => 'Sei sicuro di voler eliminare questa cartella e tutto il suo contenuto? Questa azione non può essere annullata. Digita il nome della cartella per confermare: "{name}"',
	'confirm.image_in_use'         => 'Questa immagine è attualmente utilizzata nel contenuto. Eliminandola si interromperà il riferimento. Continuare?',
	'confirm.file_in_use'          => 'Questo file è attualmente referenziato nel contenuto. Eliminandolo si interromperà il link. Continuare?',
	'confirm.video_in_use'         => 'Questo {label} è attualmente utilizzato nel contenuto. Eliminandolo si interromperà il riferimento. Continuare?',
	'confirm.delete_label'         => 'Eliminare questo {label}?',

	// Confirm dialog buttons — defaults for every tcmsConfirm() call
	'confirm.yes_sure'             => 'Sì, confermo',
	'confirm.cancel'               => 'Annulla',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Aggiornamento dello stato in evidenza non riuscito. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.cache_clear'            => 'Cancellazione della cache non riuscita. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.delete_image'           => "Eliminazione dell'immagine non riuscita. Si è verificato un errore di rete o di timeout. Riprova.",
	'error.delete_file'            => 'Eliminazione del file non riuscita. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.rename_folder'          => 'Rinomina della cartella non riuscita. Si è verificato un errore di rete o di timeout. Riprova.',
	'error.delete_label'           => 'Eliminazione di {label} non riuscita',
	'error.no_processed_images'    => 'Nessuna immagine elaborata da scaricare',
	'error.enter_definition'       => 'Inserisci prima una definizione Twig',
	'error.testing_view'           => 'Errore nel test della vista: {message}',

	// ── Depot Field ─────────────────────────────────────────────────────────
	'depot.processing'               => 'Elaborazione...',
	'depot.files_sent'               => 'I tuoi file sono stati inviati.',
	'depot.file_too_big'             => 'Questo file è troppo grande ({{filesize}} MB). Il limite è {{maxFilesize}} MB.',
	'depot.invalid_file_type'        => 'Questo tipo di file non è accettato.',
	'depot.upload_failed'            => 'Il caricamento non è andato a buon fine. Riprova.',
	'depot.upload_expired'           => 'Questo modulo è scaduto. Ricarica la pagina e riprova.',
	'depot.remove_file'              => 'Rimuovi',

	// ── Markdown Field ──────────────────────────────────────────────────────
	'markdown.visual_title'        => "Passare all'editor visuale?",
	'markdown.visual_lossy'        => "Questo contenuto usa {syntax}, che l'editor visuale non può conservare. La modifica in modalità visuale lo rimuoverà.",
	'markdown.visual_confirm'      => 'Passa comunque',
	'markdown.source_notice'       => "Aperto in modalità sorgente perché questo contenuto usa {syntax}, che l'editor visuale non può conservare.",
	'markdown.syntax.html'         => 'HTML grezzo',
	'markdown.syntax.comment'      => 'commenti HTML',
	'markdown.syntax.footnote'     => 'note a piè di pagina',
	'markdown.syntax.abbreviation' => 'abbreviazioni',
	'markdown.syntax.linkedImage'  => 'immagini con link',

	// ── Form messages (public forms) ────────────────────────────────────────
	'form.fix_errors'           => 'Corregga gli errori di convalida prima di salvare.',
	'form.has_errors'           => 'Errore nel modulo, impossibile salvare.',
	'form.cannot_save'          => 'Impossibile salvare: {reason}',
	'form.action_failed'        => 'Esecuzione dell\'azione non riuscita',
	'form.upload_failed'        => 'Caricamento non riuscito. Controlli i log del server per i dettagli.',
	'form.unknown_error'        => 'Errore sconosciuto: {response}',
	'form.required_file'        => 'Il campo {label} è obbligatorio: carichi un file',
	'form.required_image'       => 'Il campo {label} è obbligatorio: carichi un\'immagine',
	'form.required_images'      => 'Il campo {label} è obbligatorio: carichi almeno un\'immagine',
	'form.select_one_or_more'   => 'Selezioni almeno un\'opzione.',
	'form.select_option'        => 'Selezioni un\'opzione.',
	'form.must_be_checked'      => 'Questo campo deve essere selezionato.',
	'form.passwords_mismatch'   => 'Le password non corrispondono.',
	'form.list_required'        => 'Aggiunga almeno un elemento all\'elenco.',
	'form.deck_required'        => 'Ne aggiunga almeno uno.',
	'form.min_items'            => 'Aggiunga almeno {count} elementi',
	'form.max_items'            => 'Massimo {count} elementi consentiti',
	'form.duplicate_id'         => 'Trovato un ID duplicato: {id}',
	'form.item_id_empty'        => 'L\'ID dell\'elemento non può essere vuoto',
	'form.invalid_json'         => 'Formato JSON non valido.',
	'form.id_exists'            => 'L\'ID esiste già',
];
