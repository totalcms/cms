import '../../javascript/content.js';

//-----------------------------------------------
// Final review fix (Minor #11): the lazy iframe loader in content.js must
// also pick up a bare EmbedBuilder::iframe() output (e.g. cms.embed() on an
// unknown http(s) URL), which carries only the plain `cms-iframe` class with
// no `cms-video-embed` ancestor — previously only the two cms.render.video()
// shapes were matched, so cms.embed()'s iframe never got its `src` swapped
// in and stayed unloaded.
//-----------------------------------------------

function fireDomContentLoaded() {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true, cancelable: true }));
}

test('lazy-loads the cms-video-embed wrapper shape (cms.render.video, unknown provider)', () => {
    document.body.innerHTML = `
        <div class="cms-video-embed" style="--cms-video-ratio:16 / 9">
            <iframe class="cms-iframe" data-src="https://example.com/embed-a"></iframe>
        </div>
    `;

    fireDomContentLoaded();

    const iframe = document.querySelector('iframe');
    expect(iframe.src).toBe('https://example.com/embed-a');
});

test('lazy-loads a bare EmbedBuilder::iframe() output (cms.embed() on an unknown URL)', () => {
    document.body.innerHTML = `
        <iframe class="cms-iframe" data-src="https://example.com/embed-b"></iframe>
    `;

    fireDomContentLoaded();

    const iframe = document.querySelector('iframe');
    expect(iframe.src).toBe('https://example.com/embed-b');
});

test('lazy-loads the legacy shape where cms-video-embed is on the iframe itself', () => {
    document.body.innerHTML = `
        <iframe class="cms-video-embed" data-src="https://example.com/embed-c"></iframe>
    `;

    fireDomContentLoaded();

    const iframe = document.querySelector('iframe');
    expect(iframe.src).toBe('https://example.com/embed-c');
});
