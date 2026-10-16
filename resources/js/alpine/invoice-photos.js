/**
 * Supplier invoice photos: "Take photo" opens the phone camera, "Choose files" opens the
 * gallery / file picker. Photos are shrunk to a readable JPEG (longest side 2000 px) before
 * upload, so a 6 MB phone photo goes up as about 0.5 MB. The picked files are copied into
 * the hidden <input x-ref="output" name="…[]"> that the form submits.
 *
 * Used by <x-purchasing.invoice-photos-input>.
 */
const MAX_SIDE = 2000;
const QUALITY = 0.82;

function loadImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => resolve({ image, url });
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('unreadable image'));
        };
        image.src = url;
    });
}

/**
 * The browser applies the photo's EXIF rotation when it draws an <img>, so the canvas
 * copy comes out the right way up.
 */
async function shrink(file) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') {
        return file;
    }

    let loaded;
    try {
        loaded = await loadImage(file);
    } catch {
        return file;
    }

    const { image, url } = loaded;
    const scale = Math.min(1, MAX_SIDE / Math.max(image.naturalWidth, image.naturalHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(image.naturalWidth * scale);
    canvas.height = Math.round(image.naturalHeight * scale);

    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    URL.revokeObjectURL(url);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));

    if (!blob || (blob.size >= file.size && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type))) {
        return file;
    }

    const name = (file.name || 'invoice').replace(/\.[^.]+$/, '') + '.jpg';

    return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
}

export default function invoicePhotos({ max = 10, maxBytes = 10 * 1024 * 1024 } = {}) {
    return {
        max,
        items: [],
        busy: false,
        error: '',

        get canAddMore() {
            return this.items.length < this.max;
        },

        async add(event) {
            const picked = Array.from(event.target.files ?? []);
            event.target.value = '';
            this.error = '';

            if (!picked.length) {
                return;
            }

            this.busy = true;

            for (const original of picked) {
                if (!this.canAddMore) {
                    this.error = `Up to ${this.max} files at a time.`;
                    break;
                }

                const file = await shrink(original);

                if (file.size > maxBytes) {
                    this.error = `${original.name} is too large (over ${Math.round(maxBytes / 1024 / 1024)} MB).`;
                    continue;
                }

                this.items.push({
                    key: `${Date.now()}-${Math.random()}`,
                    file,
                    name: file.name,
                    isImage: file.type.startsWith('image/'),
                    preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                    size: file.size,
                });
            }

            this.busy = false;
            this.sync();
        },

        remove(index) {
            const [item] = this.items.splice(index, 1);
            if (item?.preview) {
                URL.revokeObjectURL(item.preview);
            }
            this.sync();
        },

        sync() {
            const transfer = new DataTransfer();
            this.items.forEach((item) => transfer.items.add(item.file));
            this.$refs.output.files = transfer.files;
        },

        sizeText(bytes) {
            return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
        },
    };
}
