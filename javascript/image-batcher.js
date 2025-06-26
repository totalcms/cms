/**
 * Image Batcher - Batch processing JavaScript for processing multiple images with ImageWorks
 */

class ImageBatcher {
	constructor() {
		this.batchData      = window.batchData || {};
		this.progressFill   = document.getElementById('progress-fill');
		this.progressText   = document.getElementById('progress-text');
		this.batchPreview   = document.getElementById('batch-preview');
		this.restartButton  = document.getElementById('restart-batch');
		this.downloadButton = document.getElementById('download-all');

		this.images = [];
		this.processedImages = [];
		this.currentIndex = 0;

		this.init();
	}

	async init() {
		this.setupEventListeners();
		await this.loadImages();
		await this.startBatchProcessing();
	}

	setupEventListeners() {
		if (this.restartButton) {
			this.restartButton.addEventListener('click', () => {
				window.location.href = window.location.pathname;
			});
		}

		if (this.downloadButton) {
			this.downloadButton.addEventListener('click', () => {
				this.downloadAllImages();
			});
		}
	}

	async loadImages() {
		this.updateProgress(0, 'Loading images...');

		try {
			// Fetch collection data to get all items
			const response = await fetch(`${this.batchData.apiUrl}/collections/${this.batchData.collection}/index`);

			if (!response.ok) {
				throw new Error(`Failed to fetch collection: ${response.statusText}`);
			}

			const responseData = await response.json();
			console.log('API Response:', responseData); // Debug log
			const collectionData = responseData.data.objects;

			// Extract images from the collection based on property type
			this.images = this.extractImages(collectionData);

			if (this.images.length === 0) {
				this.updateProgress(100, 'No images found in this collection property.');
				return;
			}

			this.updateProgress(0, `Found ${this.images.length} images to process`);

		} catch (error) {
			console.error('Error loading images:', error);
			this.updateProgress(0, 'Error loading images: ' + error.message);
		}
	}

	extractImages(collectionData) {
		const images = [];

		// Ensure collectionData is an array
		if (!Array.isArray(collectionData)) {
			console.error('Collection data is not an array:', collectionData);
			return images;
		}

		for (const object of collectionData) {
			// Skip if object doesn't have an id
			if (!object || !object.id) {
				console.warn('Skipping object without id:', object);
				continue;
			}

			const propertyValue = object[this.batchData.property];

			if (!propertyValue) {
				console.log(`No ${this.batchData.property} property found in object ${object.id}`);
				continue;
			}

			// Handle different property types
			if (Array.isArray(propertyValue)) {
				// Gallery property - array of images
				for (const image of propertyValue) {
					if (image && typeof image === 'object' && image.name) {
						images.push({
							id   : object.id,
							name : image.name,
							type : 'gallery',
						});
					}
				}
			} else if (typeof propertyValue === 'object' && propertyValue.name) {
				// Image property - single image object
				images.push({
					id   : object.id,
					name : propertyValue.name,
					type : 'image',
				});
			} else if (typeof propertyValue === 'string') {
				// Styled text or other string property containing images
				// We would need to parse the content to find image references
				// For now, skip styled text processing
				console.log(`Styled text processing not yet implemented for object ${object.id}`);
			}
		}

		return images;
	}

	async startBatchProcessing() {
		if (this.images.length === 0) {
			return;
		}

		this.currentIndex = 0;
		this.processedImages = [];

		// Create preview containers for all images
		this.createPreviewContainers();

		// Process images one by one
		for (let i = 0; i < this.images.length; i++) {
			await this.processImage(i);
		}

		// Show completion
		this.updateProgress(100, `Successfully processed ${this.processedImages.length} images`);
		this.showCompletionButtons();
	}

	createPreviewContainers() {
		this.batchPreview.innerHTML = '';

		for (let i = 0; i < this.images.length; i++) {
			const image = this.images[i];
			const container = document.createElement('div');
			container.className = 'batch-item';
			container.innerHTML = `
				<div class="loading">Processing...</div>
			`;
			container.id = `batch-item-${i}`;
			this.batchPreview.appendChild(container);
		}
	}

	async processImage(index) {
		const image = this.images[index];
		this.currentIndex = index;

		const progressPercent = (index / this.images.length) * 100;
		this.updateProgress(progressPercent, `Processing ${image.name} (${index + 1}/${this.images.length})`);

		try {
			// Generate the image URL with ImageWorks parameters
			const imageUrl = this.generateImageUrl(image);

			// Update preview container
			const container = document.getElementById(`batch-item-${index}`);
			if (container) {
				container.innerHTML = `
					<img src="${imageUrl}" alt="${image.name}" loading="lazy">
					<small>✓ Processed</small>
				`;
			}

			// Add to processed images
			this.processedImages.push({
				...image,
				processedUrl: imageUrl
			});

			// Small delay to show progress
			await this.delay(100);

		} catch (error) {
			console.error(`Error processing image ${image.name}:`, error);

			const container = document.getElementById(`batch-item-${index}`);
			if (container) {
				container.innerHTML = `
					<div class="error">Failed to process</div>
					<small>❌ Error: ${error.message}</small>
				`;
			}
		}
	}

	generateImageUrl(image) {
		// Parse the example URL to extract ImageWorks parameters
		const exampleUrl = new URL(this.batchData.example);
		const imageworksParams = new URLSearchParams(exampleUrl.search);

		const extension = imageworksParams.get('fm') || image.name.split('.').pop();

		// Build the base URL for this specific image
		let baseUrl = `${this.batchData.apiUrl}/imageworks/${this.batchData.collection}/${image.id}/${this.batchData.property}.${extension}`;
		const params = new URLSearchParams();

		if (image.type === 'gallery') {
			const basename = image.name.split('.').slice(0, -1).join('.');
			baseUrl = `${this.batchData.apiUrl}/imageworks/${this.batchData.collection}/${image.id}/${this.batchData.property}/${basename}.${extension}`;
		}

		// First, copy ALL ImageWorks parameters from the example URL
		// These are the processing parameters (w, h, fit, q, fm, etc.)
		for (const [key, value] of imageworksParams) {
			params.set(key, value);
		}

		// Then override/add the specific parameters for this image
		params.set('collection', this.batchData.collection);

		// If it's a gallery image, add the name parameter
		if (image.type === 'gallery') {
			params.set('name', image.name);
		}

		return `${baseUrl}?${params.toString()}`;
	}

	updateProgress(percent, text) {
		if (this.progressFill) {
			this.progressFill.style.width = `${percent}%`;
		}
		if (this.progressText) {
			this.progressText.textContent = text;
		}
	}

	showCompletionButtons() {
		if (this.restartButton) {
			this.restartButton.style.display = 'inline-block';
		}
		if (this.downloadButton) {
			this.downloadButton.style.display = 'inline-block';
		}
	}

	async downloadAllImages() {
		if (this.processedImages.length === 0) {
			alert('No processed images to download');
			return;
		}

		// Create a zip file or download images individually
		// For now, we'll download them individually
		for (const image of this.processedImages) {
			try {
				const link = document.createElement('a');
				link.href = image.processedUrl;
				link.download = `processed_${image.name}`;
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);

				// Small delay between downloads
				await this.delay(200);
			} catch (error) {
				console.error(`Error downloading ${image.name}:`, error);
			}
		}
	}

	delay(ms) {
		return new Promise(resolve => setTimeout(resolve, ms));
	}
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
	// Only initialize if we're on the batch processing step
	if (window.batchData && document.getElementById('batch-preview')) {
		new ImageBatcher();
	}
});