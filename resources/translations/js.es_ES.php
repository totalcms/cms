<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => '¿Está seguro de que desea eliminar esta imagen?',
	'confirm.delete_file'          => '¿Está seguro de que desea eliminar este archivo?',
	'confirm.delete_item'          => '¿Está seguro de que desea eliminar esto? Esta acción no se puede deshacer.',
	'confirm.delete_files'         => '¿Está seguro de que desea eliminar {count} archivos? Esta acción no se puede deshacer.',
	'confirm.delete_folder_name'   => 'El nombre de la carpeta ingresado no coincide. Eliminación cancelada.',
	'confirm.image_in_use'         => 'Esta imagen se está utilizando actualmente en el contenido. Eliminarla romperá la referencia. ¿Desea continuar?',
	'confirm.file_in_use'          => 'Este archivo está referenciado actualmente en el contenido. Eliminarlo romperá el enlace. ¿Desea continuar?',
	'confirm.video_in_use'         => 'Este {label} se está utilizando actualmente en el contenido. Eliminarlo romperá la referencia. ¿Desea continuar?',
	'confirm.delete_label'         => '¿Eliminar este {label}?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'No se pudo actualizar el estado de destacado. Se produjo un error de red o de tiempo de espera. Por favor, inténtelo de nuevo.',
	'error.cache_clear'            => 'No se pudo limpiar la caché. Se produjo un error de red o de tiempo de espera. Por favor, inténtelo de nuevo.',
	'error.delete_image'           => 'No se pudo eliminar la imagen. Se produjo un error de red o de tiempo de espera. Por favor, inténtelo de nuevo.',
	'error.delete_file'            => 'No se pudo eliminar el archivo. Se produjo un error de red o de tiempo de espera. Por favor, inténtelo de nuevo.',
	'error.delete_label'           => 'No se pudo eliminar {label}',
	'error.no_processed_images'    => 'No hay imágenes procesadas para descargar',
	'error.enter_definition'       => 'Por favor, introduzca primero una definición de Twig',
	'error.testing_view'           => 'Error al probar la vista: {message}',
];
