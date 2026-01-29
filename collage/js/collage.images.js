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
        fileInput.setAttribute('capture', 'environment');
        fileInput.style.cssText = 'position:absolute;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(fileInput);
        fileInput.addEventListener('change', handleFileSelect);
    }

    function handleFileSelect(e) {
        const files = Array.from(e.target.files);
        files.forEach((file, index) => {
            const reader = new FileReader();
            reader.onload = (event) => {
                addImageToCanvas(event.target.result, index);
            };
            reader.readAsDataURL(file);
        });
        fileInput.value = '';
    }

    function addImageToCanvas(src, index) {
        const canvasRect = CollageState.getCanvasRect();
        const existingCount = CollageState.getImages().length;
        const offsetIndex = existingCount + index;

        const x = SPACING + (offsetIndex % 4) * (DEFAULT_SIZE + SPACING);
        const y = SPACING + Math.floor(offsetIndex / 4) * (DEFAULT_SIZE + SPACING);

        const imageData = {
            id: generateId(),
            src: src,
            x: Math.min(x, canvasRect.width - DEFAULT_SIZE - SPACING),
            y: Math.min(y, canvasRect.height - DEFAULT_SIZE - SPACING),
            width: DEFAULT_SIZE,
            height: DEFAULT_SIZE,
            rotation: 0
        };

        CollageState.addImage(imageData);
        renderImage(imageData);
    }

    function renderImage(imageData) {
        const item = document.createElement('div');
        item.className = 'collage-item';
        item.dataset.id = imageData.id;
        applyTransform(item, imageData);

        const img = document.createElement('img');
        img.src = imageData.src;
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
            removeHandles(item);
        });

        const item = canvas.querySelector(`[data-id="${id}"]`);
        if (item) {
            item.classList.add('selected');
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
            item.remove();
        }
    }

    function clearAllImages() {
        canvas.querySelectorAll('.collage-item').forEach(item => item.remove());
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
        selectImage,
        removeImageElement,
        clearAllImages
    };
})();
