// HEIC/HEIF は端末内で変換し、成功した選択だけを Livewire に渡す。
export const createHeicUploads = (loadConverter = () => import('heic-to')) => ({
    converting: false,
    error: '',

    async prepare(event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') return;
        if (input.dataset.heicReady === '1') {
            delete input.dataset.heicReady;
            return;
        }

        this.error = '';
        const files = Array.from(input.files ?? []);
        const isHeicName = (file) => /\.(heic|heif)$/i.test(file.name);
        const isHeicType = (file) => /^image\/hei[cf](?:-sequence)?$/i.test(file.type);
        if (!files.some((file) => isHeicName(file) || isHeicType(file))) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        this.converting = true;

        try {
            const { heicTo, isHeic } = await loadConverter();
            const transfer = new DataTransfer();

            for (const file of files) {
                if (isHeicName(file) || isHeicType(file)) {
                    if (file.size > 10 * 1024 * 1024) throw new Error('too-large');
                    if (!await isHeic(file)) throw new Error('invalid-heic');

                    const blob = await heicTo({ blob: file, type: 'image/jpeg', quality: 0.9 });
                    const name = isHeicName(file) ? file.name.replace(/\.(heic|heif)$/i, '.jpg') : `${file.name}.jpg`;
                    const jpeg = new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified });
                    if (jpeg.size > 10 * 1024 * 1024) throw new Error('too-large');
                    transfer.items.add(jpeg);
                } else {
                    transfer.items.add(file);
                }
            }

            input.files = transfer.files;
            // Livewire captures disabled controls when the upload starts. Let Alpine
            // release them first so they are not restored as permanently disabled.
            this.converting = false;
            await this.$nextTick();
            input.dataset.heicReady = '1';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        } catch {
            input.value = '';
            this.error = 'HEIC/HEIFを変換できませんでした。JPEGで保存してから選び直してください。';
        } finally {
            this.converting = false;
        }
    },
});
