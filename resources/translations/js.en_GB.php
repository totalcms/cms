<?php

declare(strict_types=1);

return [
	// ── Confirmation Dialogs ────────────────────────────────────────────────
	'confirm.delete_image'         => 'Are you sure that you want to delete this image?',
	'confirm.delete_file'          => 'Are you sure that you want to delete this file?',
	'confirm.delete_item'          => 'Are you sure that you want to delete this? This cannot be undone.',
	'confirm.delete_files'         => 'Are you sure that you want to delete {count} files? This cannot be undone.',
	'confirm.delete_folder_name'   => 'Folder name entered does not match. Deletion cancelled.',
	'confirm.image_in_use'         => 'This image is currently used in the content. Deleting it will break the reference. Continue?',
	'confirm.file_in_use'          => 'This file is currently referenced in the content. Deleting it will break the link. Continue?',
	'confirm.video_in_use'         => 'This {label} is currently used in the content. Deleting it will break the reference. Continue?',
	'confirm.delete_label'         => 'Delete this {label}?',

	// ── Error Messages ──────────────────────────────────────────────────────
	'error.featured_update'        => 'Failed to update featured status. A network or timeout error occurred. Please try again.',
	'error.cache_clear'            => 'Failed to clear cache. A network or timeout error occurred. Please try again.',
	'error.delete_image'           => 'Failed to delete image. A network or timeout error occurred. Please try again.',
	'error.delete_file'            => 'Failed to delete file. A network or timeout error occurred. Please try again.',
	'error.delete_label'           => 'Failed to delete {label}',
	'error.no_processed_images'    => 'No processed images to download',
	'error.enter_definition'       => 'Please enter a Twig definition first',
	'error.testing_view'           => 'Error testing view: {message}',
];
