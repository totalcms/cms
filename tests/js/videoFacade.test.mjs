import initVideoFacades, { warmFacade } from '../../javascript/video-facade.js';

//-----------------------------------------------
// The click delegates on the facade element itself (poster, button, or the
// box), the iframe slides in UNDER the poster and the poster only goes once
// the iframe has loaded (or a hold timeout passes), and the first hover
// preconnects the player's origins.
//
// A player that takes a "play" message (Livid, over player.js) is built under
// the poster once the pointer rests on it, with autoplay off, and the click
// sends "play" instead of building an iframe.
//-----------------------------------------------
import { vi } from 'vite-plus/test';

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

test('a Jet-Stream facade also preconnects the load balancer the player fetches its playlist and poster from', () => {
    const { facade } = buildFacade();
    facade.dataset.embed = 'https://player.jet-stream.com/?account=demo&file=clip.smil&type=streaming&service=wowza&output=player&autostart=1';
    document.head.querySelectorAll('link[rel=preconnect]').forEach(l => l.remove());

    warmFacade(facade);

    const hrefs = Array.from(document.head.querySelectorAll('link[rel=preconnect]')).map(l => l.getAttribute('href'));
    expect(hrefs).toContain('https://player.jet-stream.com');
    expect(hrefs).toContain('https://takeoff.jetstre.am');
});

test('warming a facade with a non-http embed adds nothing', () => {
    const { facade } = buildFacade();
    facade.dataset.embed = 'javascript:alert(1)';
    const before = document.head.querySelectorAll('link[rel=preconnect]').length;

    warmFacade(facade);

    expect(document.head.querySelectorAll('link[rel=preconnect]').length).toBe(before);
});

//-----------------------------------------------
// Preload: a controllable player is built ahead of the click
//-----------------------------------------------
const LIVID_EMBED = 'https://livid.com/embed/abc123?autoplay=1';
const PLAY_MESSAGE = JSON.stringify({ context: 'player.js', version: '2.0', method: 'play' });

function buildLividFacade() {
    const parts = buildFacade();
    parts.facade.dataset.embed = LIVID_EMBED;

    return parts;
}

function hover(el, init = {}) {
    el.dispatchEvent(new PointerEvent('pointerover', { bubbles: true, pointerType: 'mouse', ...init }));
}

// What the player posts to the page. `source` is set by hand: a real message
// carries the iframe's window, which a constructed event cannot claim.
function playerSays(iframe, data, { origin = 'https://livid.com', source = iframe.contentWindow } = {}) {
    const event = new MessageEvent('message', { data: JSON.stringify(data), origin });
    Object.defineProperty(event, 'source', { value: source });
    window.dispatchEvent(event);
}

function playerReady(iframe, options) {
    playerSays(iframe, { context: 'player.js', version: '2.0', event: 'ready' }, options);
}

// Hover a Livid facade and let the intent delay pass: the player is preloaded.
function preloadedFacade() {
    const parts = buildLividFacade();
    hover(parts.facade);
    vi.advanceTimersByTime(100);

    const iframe = parts.facade.querySelector('iframe');
    const post = vi.spyOn(iframe.contentWindow, 'postMessage').mockImplementation(() => {});

    return { ...parts, iframe, post };
}

describe('preloading a player that takes a play message', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    test('resting the pointer on a Livid facade builds the player under the poster with autoplay off', () => {
        const { facade, img } = buildLividFacade();

        hover(facade);
        expect(facade.querySelector('iframe')).toBeNull();

        vi.advanceTimersByTime(100);

        const iframe = facade.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe.src).toBe('https://livid.com/embed/abc123?autoplay=0');
        expect(iframe.allow).toContain('autoplay');
        // Under the poster and out of reach until the visitor opens the video.
        expect(facade.firstElementChild).toBe(iframe);
        expect(iframe.hasAttribute('inert')).toBe(true);
        // Nothing the visitor can see has changed.
        expect(facade.contains(img)).toBe(true);
        expect(facade.querySelector('button')).not.toBeNull();
        expect(facade.classList.contains('is-loading')).toBe(false);
        expect(facade.classList.contains('is-playing')).toBe(false);
    });

    test('a pointer that only crosses the facade preloads nothing', () => {
        const { facade } = buildLividFacade();

        hover(facade);
        vi.advanceTimersByTime(50);
        facade.dispatchEvent(new PointerEvent('pointerout', { bubbles: true, relatedTarget: document.body }));
        vi.advanceTimersByTime(500);

        expect(facade.querySelector('iframe')).toBeNull();
    });

    test('moving between the poster and the play button keeps the preload', () => {
        const { facade, img, button } = buildLividFacade();

        hover(img);
        img.dispatchEvent(new PointerEvent('pointerout', { bubbles: true, relatedTarget: button }));
        hover(button);
        vi.advanceTimersByTime(100);

        expect(facade.querySelectorAll('iframe').length).toBe(1);
    });

    test('a finger landing on the facade does not preload', () => {
        const { facade } = buildLividFacade();

        hover(facade, { pointerType: 'touch' });
        vi.advanceTimersByTime(500);

        expect(facade.querySelector('iframe')).toBeNull();
    });

    test('keyboard focus on the play button preloads too', () => {
        const { facade, button } = buildLividFacade();

        button.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        vi.advanceTimersByTime(100);

        expect(facade.querySelector('iframe')).not.toBeNull();
    });

    test('a player that takes no play message is not preloaded', () => {
        const { facade } = buildFacade();

        hover(facade);
        vi.advanceTimersByTime(500);

        expect(facade.querySelector('iframe')).toBeNull();
    });

    test('a visitor saving data gets no preload', () => {
        const { facade } = buildLividFacade();
        Object.defineProperty(navigator, 'connection', { value: { saveData: true }, configurable: true });

        hover(facade);
        vi.advanceTimersByTime(500);
        delete navigator.connection;

        expect(facade.querySelector('iframe')).toBeNull();
    });

    test('clicking a ready player sends play inside the click and drops the poster', () => {
        const { facade, img, button, iframe, post } = preloadedFacade();
        playerReady(iframe);

        button.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        // Sent before the click handler returned: no timer has been advanced.
        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith(PLAY_MESSAGE, 'https://livid.com');
        expect(facade.querySelectorAll('iframe').length).toBe(1);
        expect(facade.contains(img)).toBe(false);
        expect(facade.querySelector('button')).toBeNull();
        expect(iframe.hasAttribute('inert')).toBe(false);
        expect(facade.classList.contains('is-playing')).toBe(true);
    });

    test('a click before the player is ready holds the poster and plays once it is', () => {
        const { facade, img, iframe, post } = preloadedFacade();

        facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(post).not.toHaveBeenCalled();
        expect(facade.contains(img)).toBe(true);
        expect(facade.classList.contains('is-loading')).toBe(true);
        expect(facade.querySelectorAll('iframe').length).toBe(1);

        playerReady(iframe);

        expect(post).toHaveBeenCalledWith(PLAY_MESSAGE, 'https://livid.com');
        expect(facade.contains(img)).toBe(false);
        expect(facade.classList.contains('is-loading')).toBe(false);
        expect(facade.classList.contains('is-playing')).toBe(true);
    });

    test('a clicked player that never reports ready is shown after the hold timeout', () => {
        const { facade, img, iframe, post } = preloadedFacade();

        facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        vi.advanceTimersByTime(4000);

        expect(post).not.toHaveBeenCalled();
        expect(facade.contains(img)).toBe(false);
        expect(iframe.hasAttribute('inert')).toBe(false);
        expect(facade.classList.contains('is-playing')).toBe(true);
    });

    test('a ready message from another origin or another window is ignored', () => {
        const { facade, iframe, post } = preloadedFacade();

        playerReady(iframe, { origin: 'https://example.com' });
        playerReady(iframe, { source: window });
        playerSays(iframe, { context: 'player.js', version: '2.0', event: 'play' });
        facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        // Not ready as far as the facade knows: it waits instead of sending play.
        expect(post).not.toHaveBeenCalled();
        expect(facade.classList.contains('is-loading')).toBe(true);
    });

    test('a click with no preload builds the player on click, as before', () => {
        const { facade, button } = buildLividFacade();

        button.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        const iframe = facade.querySelector('iframe');
        expect(iframe.src).toBe(LIVID_EMBED);
        expect(iframe.hasAttribute('inert')).toBe(false);
        expect(facade.classList.contains('is-loading')).toBe(true);
    });

    test('a click during the intent delay does not preload a second player afterwards', () => {
        const { facade } = buildLividFacade();

        hover(facade);
        facade.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        vi.advanceTimersByTime(500);

        expect(facade.querySelectorAll('iframe').length).toBe(1);
        expect(facade.querySelector('iframe').src).toBe(LIVID_EMBED);
    });
});
