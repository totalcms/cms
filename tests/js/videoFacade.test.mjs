import initVideoFacades, { warmFacade } from '../../javascript/video-facade.js';

//-----------------------------------------------
// The click delegates on the facade element itself (poster, button, or the
// box), the iframe slides in UNDER the poster and the poster only goes once
// the iframe has loaded (or a hold timeout passes), and the first hover
// preconnects the player's origins.
//-----------------------------------------------
import { vi } from 'vitest';

function buildFacade() {
    document.body.innerHTML = '';

    const facade = document.createElement('div');
    facade.className = 'cms-video-facade';
    facade.dataset.embed = 'https://www.youtube-nocookie.com/embed/abc123?autoplay=1';

    const img = document.createElement('img');
    img.src = '/poster.jpg';
    img.alt = 'My Video';
    facade.appendChild(img);

    const button = document.createElement('button');
    button.type = 'button';
    facade.appendChild(button);

    document.body.appendChild(facade);

    return { facade, img, button };
}

// Delegated on document — wire it once, not per test, so repeated calls
// don't stack up duplicate listeners across the tests in this file.
initVideoFacades();

function loadIframe(facade) {
    facade.querySelector('iframe').dispatchEvent(new Event('load'));
}

test('clicking the button inserts the iframe under the poster and keeps the poster until it has loaded', () => {
    const { facade, button, img } = buildFacade();

    button.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    const iframe = facade.querySelector('iframe');
    expect(iframe).not.toBeNull();
    expect(iframe.src).toBe(facade.dataset.embed);
    // Under: first child. Poster and button still there, facade marked loading.
    expect(facade.firstElementChild).toBe(iframe);
    expect(facade.contains(img)).toBe(true);
    expect(facade.querySelector('button')).not.toBeNull();
    expect(facade.classList.contains('is-loading')).toBe(true);
    expect(facade.classList.contains('is-playing')).toBe(false);

    loadIframe(facade);

    expect(facade.contains(img)).toBe(false);
    expect(facade.querySelector('button')).toBeNull();
    expect(facade.children.length).toBe(1);
    expect(facade.classList.contains('is-loading')).toBe(false);
    expect(facade.classList.contains('is-playing')).toBe(true);
});

test('a second click while loading does not build a second iframe', () => {
    const { facade, button } = buildFacade();

    button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    button.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    expect(facade.querySelectorAll('iframe').length).toBe(1);
});

test('the poster is dropped after the hold timeout even if the iframe never fires load', () => {
    vi.useFakeTimers();
    const { facade, img } = buildFacade();

    facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(facade.contains(img)).toBe(true);

    vi.advanceTimersByTime(4000);

    expect(facade.contains(img)).toBe(false);
    expect(facade.classList.contains('is-playing')).toBe(true);
    vi.useRealTimers();
});

test('clicking the poster image (not just the button) also swaps in the iframe', () => {
    const { facade, img } = buildFacade();

    img.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    loadIframe(facade);

    expect(facade.querySelector('iframe')).not.toBeNull();
    expect(facade.classList.contains('is-playing')).toBe(true);
});

test('clicking the facade element itself (no nested target) also swaps in the iframe', () => {
    const { facade } = buildFacade();

    facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    expect(facade.querySelector('iframe')).not.toBeNull();
});

test('clicking outside any facade does nothing', () => {
    const { facade } = buildFacade();

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    expect(facade.querySelector('iframe')).toBeNull();
    expect(facade.classList.contains('is-playing')).toBe(false);
});

test('hovering a facade preconnects the player origin and its second-hop origins, once', () => {
    const { facade } = buildFacade();
    document.head.querySelectorAll('link[rel=preconnect]').forEach(l => l.remove());

    facade.dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));
    facade.dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));

    const hrefs = Array.from(document.head.querySelectorAll('link[rel=preconnect]')).map(l => l.getAttribute('href'));
    expect(hrefs).toContain('https://www.youtube-nocookie.com');
    expect(hrefs).toContain('https://www.google.com');
    expect(hrefs).toContain('https://i.ytimg.com');
    // Each origin once, no matter how many hovers or how many facades share it.
    expect(new Set(hrefs).size).toBe(hrefs.length);

    const { facade: second } = buildFacade();
    warmFacade(second);
    expect(document.head.querySelectorAll('link[rel=preconnect]').length).toBe(hrefs.length);
});

test('warming a facade with a non-http embed adds nothing', () => {
    const { facade } = buildFacade();
    facade.dataset.embed = 'javascript:alert(1)';
    const before = document.head.querySelectorAll('link[rel=preconnect]').length;

    warmFacade(facade);

    expect(document.head.querySelectorAll('link[rel=preconnect]').length).toBe(before);
});
