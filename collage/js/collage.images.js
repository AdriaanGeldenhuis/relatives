/**
 * Collage Images Module
 * Handles image file selection and rendering
 */
const CollageImages = (() => {
    let fileInput = null;
    let canvas = null;
    let idCounter = 0;

    const DEFAULT_SIZE = 150;
    const SPACING = 20;

    function generateId() {
        return `img-${Date.now()}-${idCounter++}`;
    }

    function createFileInput() {
        fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.multiple = true;
        fileInput.accept = 'image/*';
        fileInput.hidden = true;
        document.body.appendChild(fileInput);
        fileInput.addEventListener('change', handleFileSelect);
    }

    function handleFileSelect(event) {
        const files = Array.from(event.target.files);
        fileInput.value = '';
        void addFiles(files);
    }

    async function addFiles(files) {
        if (!files.length || CollageState.get('loading') || CollageState.isLocked()) return;
        CollageState.set('loading', true);
        const status = document.getElementById('collageStatus');
        status.textContent = 'Opening photos…';
        try {
            const results = await Promise.allSettled(files.map(async file => {
                const url = URL.createObjectURL(file);
                const image = new Image();
                image.src = url;
                try {
                    await image.decode();
                    return { url, name: file.name, aspect: image.naturalHeight / image.naturalWidth };
                } catch (error) {
                    URL.revokeObjectURL(url);
                    throw error;
                }
            }));
            const loaded = results.filter(result => result.status === 'fulfilled');
            loaded.forEach(({ value }) => addImageToCanvas(value));
            if (loaded.length) CollageLayouts.applyLayout(CollageState.get('layout'));
            status.textContent = loaded.length === files.length ? '' : 'Some photos could not be opened. Try JPG or PNG copies.';
        } finally {
            CollageState.set('loading', false);
        }
    }

    function addImageToCanvas({ url: src, name, aspect }) {
        const canvasRect = CollageState.getCanvasRect();
        const existingCount = CollageState.getImages().length;
        const size = Math.max(1, Math.min(DEFAULT_SIZE, canvasRect.width - SPACING * 2));
        const columns = Math.max(1, Math.floor((canvasRect.width - SPACING) / (size + SPACING)));
        const x = SPACING + (existingCount % columns) * (size + SPACING);
        const y = SPACING + Math.floor(existingCount / columns) * (size + SPACING);

        const imageData = {
            id: generateId(),
            src,
            name,
            aspect,
            x: Math.max(0, Math.min(x, canvasRect.width - size - SPACING)),
            y: Math.max(0, Math.min(y, canvasRect.height - size - SPACING)),
            width: size,
            height: size,
            rotation: 0
        };

        CollageState.addImage(imageData);
        renderImage(imageData);
    }

    function renderImage(imageData) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'collage-item';
        item.setAttribute('aria-label', imageData.name);
        item.setAttribute('aria-pressed', 'false');
        item.dataset.id = imageData.id;
        applyTransform(item, imageData);

        const img = document.createElement('img');
        img.src = imageData.src;
        img.alt = '';
        img.draggable = false;

        item.appendChild(img);
        canvas.appendChild(item);

        item.addEventListener('click', (e) => {
            e.stopPropagation();
            if (!CollageState.isLocked()) {
                selectImage(imageData.id);
            }
        });
    }

    function applyTransform(el, data) {
        el.style.left = `${data.x}px`;
        el.style.top = `${data.y}px`;
        el.style.width = `${data.width}px`;
        el.style.height = `${data.height}px`;
        el.style.transform = `rotate(${data.rotation}deg)`;
    }

    function selectImage(id) {
        document.querySelectorAll('.collage-item').forEach(item => {
            item.classList.remove('selected');
            item.setAttribute('aria-pressed', 'false');
            removeHandles(item);
        });

        const item = canvas.querySelector(`[data-id="${id}"]`);
        if (item) {
            item.classList.add('selected');
            item.setAttribute('aria-pressed', 'true');
            addHandles(item, id);
            CollageState.selectImage(id);
        }
    }

    function addHandles(item, id) {
        const resizeHandles = ['nw', 'ne', 'sw', 'se'];
        resizeHandles.forEach(pos => {
            const handle = document.createElement('div');
            handle.className = `resize-handle resize-${pos}`;
            handle.dataset.handle = pos;
            item.appendChild(handle);
        });

        const rotateHandle = document.createElement('div');
        rotateHandle.className = 'rotate-handle';
        item.appendChild(rotateHandle);
    }

    function removeHandles(item) {
        item.querySelectorAll('.resize-handle, .rotate-handle').forEach(h => h.remove());
    }

    function updateImageElement(id) {
        const data = CollageState.getImageById(id);
        const item = canvas.querySelector(`[data-id="${id}"]`);
        if (item && data) {
            applyTransform(item, data);
        }
    }

    function removeImageElement(id) {
        const item = canvas.querySelector(`[data-id="${id}"]`);
        if (item) {
            URL.revokeObjectURL(item.querySelector('img').src);
            item.remove();
        }
    }

    function clearAllImages() {
        canvas.querySelectorAll('.collage-item').forEach(item => removeImageElement(item.dataset.id));
    }

    function openFilePicker() {
        if (CollageState.isLocked()) return;
        if (!fileInput) createFileInput();
        fileInput.click();
    }

    function init(canvasElement) {
        canvas = canvasElement;
        CollageState.subscribe((key, value) => {
            if (key === 'imageUpdate') {
                updateImageElement(value.id);
            } else if (key === 'clear') {
                clearAllImages();
            }
        });
    }

    return {
        init,
        openFilePicker,
        addFiles,
        selectImage,
        removeImageElement,
        clearAllImages
    };
})();
