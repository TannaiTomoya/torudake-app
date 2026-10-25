import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createHeicUploads } from '../../resources/js/heic-uploads.js';

// The browser-only file input APIs are modeled here; decoding itself is checked in a browser.
globalThis.HTMLInputElement = class {
    type = 'file';
    dataset = {};
    value = 'selected';
    constructor(files) { this.files = files; }
    dispatchEvent(event) { this.dispatched = event; }
};
globalThis.DataTransfer = class {
    files = [];
    items = { add: (file) => this.files.push(file) };
};

const image = (name = 'photo.HEIC', type = 'image/heic', bytes = 32) => new File(
    [new Uint8Array(bytes)], name, { type, lastModified: 1234 },
);
const eventFor = (input) => ({
    target: input, blocked: false,
    preventDefault() { this.prevented = true; },
    stopImmediatePropagation() { this.blocked = true; },
});
const uploads = (loader) => Object.assign(createHeicUploads(loader), { $nextTick: async () => {} });
const converter = (overrides = {}) => async () => ({
    isHeic: async () => true,
    heicTo: async () => new Blob(['synthetic jpeg'], { type: 'image/jpeg' }),
    ...overrides,
});

test('HEIC/HEIF extensions and sequence MIME types become JPEG before the upload event', async () => {
    for (const file of [image(), image('photo.heif', ''), image('camera', 'image/heif'), image('camera', 'image/heif-sequence'), image('camera', 'image/heic-sequence')]) {
        const state = uploads(converter());
        const input = new HTMLInputElement([file]);
        const event = eventFor(input);
        await state.prepare(event);
        assert.equal(event.blocked, true, file.type || file.name);
        assert.equal(input.files[0].type, 'image/jpeg');
        assert.equal(input.files[0].lastModified, 1234);
        assert.equal(input.dispatched.type, 'change');
        assert.equal(input.dispatched.bubbles, true);
        assert.equal(state.converting, false);
        assert.equal(state.error, '');
        const uploadEvent = eventFor(input);
        await state.prepare(uploadEvent);
        assert.equal(uploadEvent.blocked, false);
        assert.equal(input.dataset.heicReady, undefined);
    }
});

test('mixed selection preserves order, JPEG/PDF files and converted JPEG filename', async () => {
    const jpeg = image('already.jpg', 'image/jpeg');
    const pdf = image('document.pdf', 'application/pdf');
    const input = new HTMLInputElement([jpeg, image(), pdf]);
    await uploads(converter()).prepare(eventFor(input));
    assert.equal(input.files[0], jpeg);
    assert.equal(input.files[1].name, 'photo.jpg');
    assert.equal(input.files[2], pdf);
});

test('a failed conversion prevents the entire selection from reaching the uploader', async () => {
    const state = uploads(converter({ heicTo: async () => { throw new Error('decode failed'); } }));
    const input = new HTMLInputElement([image('ok.jpg', 'image/jpeg'), image()]);
    const event = eventFor(input);
    await state.prepare(event);
    assert.equal(event.blocked, true);
    assert.equal(input.dispatched, undefined);
    assert.equal(input.value, '');
    assert.match(state.error, /JPEGで保存してから選び直してください/);
    assert.equal(state.converting, false);
});

test('invalid HEIC and input/output larger than 10MB never reach the uploader', async () => {
    for (const [file, overrides] of [
        [image(), { isHeic: async () => false }],
        [image('big.heic', 'image/heic', 10 * 1024 * 1024 + 1), {}],
        [image(), { heicTo: async () => new Blob([new Uint8Array(10 * 1024 * 1024 + 1)]) }],
    ]) {
        const state = uploads(converter(overrides));
        const input = new HTMLInputElement([file]);
        await state.prepare(eventFor(input));
        assert.equal(input.dispatched, undefined);
        assert.notEqual(state.error, '');
    }
});

test('selecting a regular JPEG after a conversion error clears the old message', async () => {
    const state = uploads(async () => { throw new Error('converter unavailable'); });
    await state.prepare(eventFor(new HTMLInputElement([image()])));
    assert.notEqual(state.error, '');
    const event = eventFor(new HTMLInputElement([image('ok.jpg', 'image/jpeg')]));
    await state.prepare(event);
    assert.equal(state.error, '');
    assert.equal(event.blocked, false);
});

test('upload event is emitted only after async conversion finishes', async () => {
    let finish;
    const state = uploads(converter({ heicTo: () => new Promise((resolve) => { finish = resolve; }) }));
    const input = new HTMLInputElement([image()]);
    const pending = state.prepare(eventFor(input));
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(state.converting, true);
    assert.equal(input.dispatched, undefined);
    finish(new Blob(['jpeg'], { type: 'image/jpeg' }));
    await pending;
    assert.equal(input.dispatched.type, 'change');
    assert.equal(state.converting, false);
});

// Livewire remembers the disabled state at upload start and restores it on completion.
test('conversion releases disabled controls before Livewire starts its upload', async () => {
    const state = uploads(converter());
    let domUpdated = false;
    state.$nextTick = async () => { domUpdated = true; };
    const input = new HTMLInputElement([image()]);
    input.dispatchEvent = () => {
        assert.equal(state.converting, false);
        assert.equal(domUpdated, true);
    };
    await state.prepare(eventFor(input));
    assert.equal(state.error, '');
});
