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
			const collectionData = await response.json();

			if (!collectionData.data) {
				throw new Error('Failed to load collection data');
			}

			// Extract images from the collection based on property type
			this.images = this.extractImages(collectionData.data);

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

		for (const object of collectionData) {
			const propertyValue = object[this.batchData.property];

			if (!propertyValue) continue;

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
				console.log('Styled text processing not yet implemented');
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
				<h4>${image.name}</h4>
				<p>Collection: ${image.id}</p>
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
					<h4>${image.name}</h4>
					<p>Collection: ${image.id}</p>
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
					<h4>${image.name}</h4>
					<p>Collection: ${image.id}</p>
					<small>❌ Error: ${error.message}</small>
				`;
			}
		}
	}

	generateImageUrl(image) {
		const baseUrl = `${this.batchData.apiUrl}/image/${image.id}/${this.batchData.property}`;
		const params = new URLSearchParams();

		// Add collection parameter
		params.set('collection', this.batchData.collection);

		// If it's a gallery image, add the name parameter
		if (image.type === 'gallery') {
			params.set('name', image.name);
		}

		// Extract ImageWorks parameters from the macro
		const imageworksParams = this.extractParametersFromMacro(this.batchData.imageworksMacro);
		
		// Add all ImageWorks parameters
		for (const [key, value] of Object.entries(imageworksParams)) {
			if (value !== null && value !== undefined && value !== '') {
				params.set(key, value);
			}
		}

		return `${baseUrl}?${params.toString()}`;
	}

	extractParametersFromMacro(macro) {
		const params = {};
		
		if (!macro) return params;

		try {
			// Extract the parameters object from the macro
			// Look for patterns like {w:600, h:400, fm:'jpg'} or {w:600,h:400,fm:'jpg'}
			const paramMatch = macro.match(/\{([^}]+)\}/);
			
			if (paramMatch) {
				const paramString = paramMatch[1];
				
				// Split by commas and parse each parameter
				const paramPairs = paramString.split(',');
				
				for (const pair of paramPairs) {
					const [key, value] = pair.split(':').map(s => s.trim());
					
					if (key && value) {
						// Remove quotes from string values
						const cleanValue = value.replace(/^['"]|['"]$/g, '');
						params[key] = cleanValue;
					}
				}
			}
		} catch (error) {
			console.error('Error parsing ImageWorks macro:', error);
		}

		return params;
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