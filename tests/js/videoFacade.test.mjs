import initVideoFacades from '../../javascript/video-facade.js';

//-----------------------------------------------
// Final review fix (Minor #9): the click delegation used to be scoped to
// `.cms-video-facade button` — clicking the poster image (or any other part
// of the facade outside the button) did nothing, even though the whole
// element is styled `cursor: pointer` (css/content.scss). It should now
// delegate on the facade element itself.
//-----------------------------------------------

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

test('clicking the button swaps in the iframe', () => {
    const { facade, button } = buildFacade();

    button.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    const iframe = facade.querySelector('iframe');
    expect(iframe).not.toBeNull();
    expect(iframe.src).toBe(facade.dataset.embed);
    expect(facade.classList.contains('is-playing')).toBe(true);
});

test('clicking the poster image (not just the button) also swaps in the iframe', () => {
    const { facade, img } = buildFacade();

    img.dispatchEvent(new MouseEvent('click', { bubbles: true }));

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
