import VideoField from '../../javascript/totalform/video.js';

//-----------------------------------------------
// VideoField renders like a card: a URL row with a live provider/title badge,
// five hidden inputs that carry whatever the server last derived, and a real
// nested `poster` image sub-field wrapped in `.card-fields` (the same class
// CardField.subFields() — inherited unchanged by VideoField — scans for).
//
// Mirrors the DOM shape VideoField::buildFormField() actually renders:
//   <div class="form-field" data-type="video">
//     <input type="hidden" name="promo">                 <!-- proxy marker: this.input/this.property -->
//     <div class="video-url">
//       <input type="url" class="video-url-input" name="promo[url]">
//       <span class="video-provider">…</span>
//       <span class="video-title">…</span>
//     </div>
//     <input type="hidden" class="video-provider-input" name="promo[provider]">
//     <input type="hidden" class="video-videoId-input" name="promo[videoId]">
//     <input type="hidden" class="video-thumbnail-input" name="promo[thumbnail]">
//     <input type="hidden" class="video-title-input" name="promo[title]">
//     <input type="hidden" class="video-aspectRatio-input" name="promo[aspectRatio]">
//     <div class="video-media has-thumbnail" data-thumbnail-label="{provider} thumbnail">
//       <img class="video-vendor-thumbnail">
//       <div class="card-fields"><div class="form-field" data-type="image">…</div></div>
//       <span class="video-media-chip poster">Poster</span>
//       <span class="video-media-chip thumbnail">Youtube thumbnail</span>
//     </div>
//   </div>
// (the real markup nests the URL row and hidden inputs in `.video-details`;
// the flat shape here is enough for the selectors video.js uses)
//-----------------------------------------------

function hiddenInput(container, key, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.className = `video-${key}-input`;
    input.name = `promo[${key}]`;
    input.value = value;
    container.appendChild(input);
    return input;
}

function buildVideoField({ url = 'https://youtu.be/abc123', provider = 'youtube', videoId = 'abc123',
    thumbnail = 'https://img.youtube.com/vi/abc123/hqdefault.jpg', title = 'My Video', aspectRatio = '16:9',
    posterValue = { name: 'poster.jpg' }, hasPoster = !!(posterValue && posterValue.name),
    posterImgSrc = '/imageworks/clips/one/promo/poster.jpg' } = {}) {
    document.body.innerHTML = '';

    const container = document.createElement('div');
    container.className = 'form-field';
    container.dataset.type = 'video';

    const marker = document.createElement('input');
    marker.type = 'hidden';
    marker.name = 'promo';
    marker.value = 'promo';
    container.appendChild(marker);

    const urlRow = document.createElement('div');
    urlRow.className = 'video-url';
    const urlInput = document.createElement('input');
    urlInput.type = 'url';
    urlInput.className = 'video-url-input';
    urlInput.name = 'promo[url]';
    urlInput.value = url;
    urlRow.appendChild(urlInput);

    const providerBadge = document.createElement('span');
    providerBadge.className = 'video-provider';
    providerBadge.textContent = provider;
    urlRow.appendChild(providerBadge);

    const titleBadge = document.createElement('span');
    titleBadge.className = 'video-title';
    titleBadge.textContent = title;
    urlRow.appendChild(titleBadge);

    container.appendChild(urlRow);

    hiddenInput(container, 'provider', provider);
    hiddenInput(container, 'videoId', videoId);
    hiddenInput(container, 'thumbnail', thumbnail);
    hiddenInput(container, 'title', title);
    hiddenInput(container, 'aspectRatio', aspectRatio);

    const media = document.createElement('div');
    media.className = 'video-media' + (thumbnail ? ' has-thumbnail' : '');
    media.dataset.thumbnailLabel = '{provider} thumbnail';
    if (thumbnail) {
        const vendor = document.createElement('img');
        vendor.className = 'video-vendor-thumbnail';
        vendor.src = thumbnail;
        media.appendChild(vendor);
    }
    container.appendChild(media);

    const cardFields = document.createElement('div');
    cardFields.className = 'card-fields';
    const posterEl = document.createElement('div');
    posterEl.className = 'form-field';
    posterEl.dataset.type = 'image';
    const posterInput = document.createElement('input');
    posterInput.name = 'poster';
    posterEl.appendChild(posterInput);

    // Mirror ImageField's real preview markup (`ImageField::imagePreview()`)
    // closely enough for VideoField.updatePreview() to find the poster's
    // already-rendered `<img>` via `.dz-preview img`.
    const dzPreview = document.createElement('div');
    dzPreview.className = 'dz-preview dz-file-preview';
    const posterImg = document.createElement('img');
    posterImg.setAttribute('data-dz-thumbnail', '');
    if (hasPoster) posterImg.src = posterImgSrc;
    posterImg.alt = (posterValue && posterValue.alt) || '';
    dzPreview.appendChild(posterImg);
    posterEl.appendChild(dzPreview);

    cardFields.appendChild(posterEl);
    media.appendChild(cardFields);

    for (const kind of ['poster', 'thumbnail']) {
        const chip = document.createElement('span');
        chip.className = `video-media-chip ${kind}`;
        chip.textContent = kind === 'poster' ? 'Poster' : 'Youtube thumbnail';
        media.appendChild(chip);
    }

    // Wire the poster's TotalField instance BEFORE constructing VideoField —
    // matching how the real form initializes children before their composite
    // parent (see cardField.test.mjs) — so VideoField.getValue()'s first call
    // (inside TotalField's constructor) can actually resolve it.
    posterEl.totalfield = {
        container: posterEl,
        property : 'poster',
        getValue : () => posterValue,
        hasImage : () => hasPoster,
        setValue : () => {},
        isUnsaved: () => false,
        saved    : () => {},
    };

    document.body.appendChild(container);

    const video = new VideoField(container, { form: { form: document.body } });

    // VideoField's own getValue() still depends on `this.urlInput` etc., which
    // are only assigned after `super()` returns — so the constructor's own
    // storedValue capture falls back to the marker's raw value (same
    // established quirk as ImageField/CardField's own preview/subfield
    // wiring). Recapture it once the field is fully constructed so tests
    // exercise real "did it change" behaviour, not that one-time fallback.
    video.storedValue = video.getValue();
    video.container.classList.remove('unsaved');

    return video;
}

describe('VideoField', () => {
    test('an input event on the URL field blanks the four derived hidden inputs and leaves aspectRatio', () => {
        const video = buildVideoField();

        video.urlInput.value = 'https://vimeo.com/999999';
        video.urlInput.dispatchEvent(new Event('input', { bubbles: true }));

        expect(video.providerInput.value).toBe('');
        expect(video.videoIdInput.value).toBe('');
        expect(video.thumbnailInput.value).toBe('');
        expect(video.titleInput.value).toBe('');
        expect(video.aspectRatioInput.value).toBe('16:9'); // untouched
    });

    test('an input event on the URL field marks the field unsaved', () => {
        const video = buildVideoField();

        video.urlInput.value = 'https://vimeo.com/999999';
        video.urlInput.dispatchEvent(new Event('input', { bubbles: true }));

        expect(video.container.classList.contains('unsaved')).toBe(true);
    });

    test('getValue() returns the composite object, including the poster sub-field\'s own value', () => {
        const video = buildVideoField({ posterValue: { name: 'poster.jpg', size: 500 } });

        expect(video.getValue()).toEqual({
            url         : 'https://youtu.be/abc123',
            provider    : 'youtube',
            videoId     : 'abc123',
            thumbnail   : 'https://img.youtube.com/vi/abc123/hqdefault.jpg',
            title       : 'My Video',
            aspectRatio : '16:9',
            poster      : { name: 'poster.jpg', size: 500 },
        });
    });

    test('a poster upload does not mark the video unsaved — the poster autosaves itself', () => {
        const video = buildVideoField({ posterValue: { name: 'poster.jpg' } });
        const posterEl = video.container.querySelector('.card-fields .form-field');

        expect(video.container.classList.contains('unsaved')).toBe(false);

        // Simulate image.js after a completed upload: the poster's value is the
        // server's, and its changed() bubbles subfield-change to the video.
        posterEl.totalfield.getValue = () => ({ name: 'new-poster.jpg', size: 900 });
        posterEl.dispatchEvent(new CustomEvent('subfield-change', { bubbles: true, detail: { field: posterEl.totalfield } }));

        expect(video.container.classList.contains('unsaved')).toBe(false);
        // The baseline followed the poster, so a later stray change event on
        // the video (e.g. a blur) does not re-flag it either.
        expect(video.storedValue.poster).toEqual({ name: 'new-poster.jpg', size: 900 });
        video.changed();
        expect(video.container.classList.contains('unsaved')).toBe(false);
    });

    test('a change inside the poster (alt text) does not mark the video unsaved either', () => {
        const video = buildVideoField({ posterValue: { name: 'poster.jpg', alt: '' } });
        const posterEl = video.container.querySelector('.card-fields .form-field');
        const altEl = document.createElement('div');
        altEl.className = 'form-field';
        posterEl.appendChild(altEl);

        posterEl.totalfield.getValue = () => ({ name: 'poster.jpg', alt: 'Hello' });
        altEl.dispatchEvent(new CustomEvent('subfield-change', { bubbles: true, detail: { field: {} } }));

        expect(video.container.classList.contains('unsaved')).toBe(false);
        expect(video.storedValue.poster).toEqual({ name: 'poster.jpg', alt: 'Hello' });
    });

    test('deleting the poster marks the poster saved and drops has-poster, without touching the video', () => {
        const video = buildVideoField({ posterValue: { name: 'poster.jpg', size: 500 }, hasPoster: true });
        const posterEl = video.container.querySelector('.card-fields .form-field');
        let savedCalls = 0;
        posterEl.totalfield.saved = () => { savedCalls++; };

        // Simulate image-preview.js after DELETE: value cleared, preview gone.
        posterEl.totalfield.getValue = () => ({ name: '', size: 0 });
        posterEl.totalfield.hasImage = () => false;
        posterEl.querySelector('.dz-preview').remove();
        posterEl.dispatchEvent(new CustomEvent('subfield-change', { bubbles: true, detail: { field: posterEl.totalfield } }));

        expect(video.container.classList.contains('unsaved')).toBe(false);
        expect(savedCalls).toBe(1);
        expect(video.mediaContainer.classList.contains('has-poster')).toBe(false);
    });

    test('a URL edit still marks the video unsaved, and a following poster upload keeps it so', () => {
        const video = buildVideoField({ posterValue: { name: 'poster.jpg' } });
        const posterEl = video.container.querySelector('.card-fields .form-field');

        video.urlInput.value = 'https://vimeo.com/999999';
        video.urlInput.dispatchEvent(new Event('input', { bubbles: true }));
        expect(video.container.classList.contains('unsaved')).toBe(true);

        posterEl.totalfield.getValue = () => ({ name: 'new-poster.jpg' });
        posterEl.dispatchEvent(new CustomEvent('subfield-change', { bubbles: true, detail: { field: posterEl.totalfield } }));
        expect(video.container.classList.contains('unsaved')).toBe(true);
    });

    test('an input event on the URL field removes the vendor thumbnail layer, leaving an empty dropzone', () => {
        const video = buildVideoField({ posterValue: { name: '', size: 0 }, hasPoster: false });

        expect(video.mediaContainer.querySelector('.video-vendor-thumbnail')).not.toBeNull();
        expect(video.mediaContainer.classList.contains('has-thumbnail')).toBe(true);

        video.urlInput.value = 'https://vimeo.com/999999';
        video.urlInput.dispatchEvent(new Event('input', { bubbles: true }));

        // The thumbnail belonged to the old URL; until the next save derives a
        // new one there is nothing to show, and the box is the poster field's
        // plain empty dropzone.
        expect(video.mediaContainer.querySelector('.video-vendor-thumbnail')).toBeNull();
        expect(video.mediaContainer.classList.contains('has-thumbnail')).toBe(false);
        expect(video.mediaContainer.classList.contains('has-poster')).toBe(false);
    });

    test('an input event on the URL field leaves the poster sub-field alone and keeps has-poster', () => {
        const video = buildVideoField({
            posterValue : { name: 'poster.jpg', size: 500 },
            hasPoster   : true,
            posterImgSrc: '/imageworks/clips/one/promo/poster.jpg',
        });

        video.urlInput.value = 'https://vimeo.com/999999';
        video.urlInput.dispatchEvent(new Event('input', { bubbles: true }));

        const posterImg = video.mediaContainer.querySelector('.card-fields .dz-preview img');
        expect(posterImg.src).toContain('/imageworks/clips/one/promo/poster.jpg');
        expect(video.mediaContainer.classList.contains('has-poster')).toBe(true);
    });

    test('setValue() with a thumbnail rebuilds the vendor layer and names the chip after the provider', () => {
        const video = buildVideoField({ thumbnail: '', provider: '', posterValue: { name: '', size: 0 }, hasPoster: false });

        expect(video.mediaContainer.querySelector('.video-vendor-thumbnail')).toBeNull();

        video.setValue({
            url: 'https://vimeo.com/999999', provider: 'vimeo', videoId: '999999',
            thumbnail: 'https://i.vimeocdn.com/video/999999.jpg', title: 'Vimeo clip', aspectRatio: '16:9',
        });

        const vendor = video.mediaContainer.querySelector('.video-vendor-thumbnail');
        expect(vendor).not.toBeNull();
        expect(vendor.getAttribute('src')).toBe('https://i.vimeocdn.com/video/999999.jpg');
        expect(video.mediaContainer.classList.contains('has-thumbnail')).toBe(true);
        expect(video.mediaContainer.querySelector('.video-media-chip.thumbnail').textContent).toBe('Vimeo thumbnail');
        expect(video.titleBadge.textContent).toBe('Vimeo clip');
    });
});
