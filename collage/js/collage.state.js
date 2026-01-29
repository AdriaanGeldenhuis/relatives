/**
 * Collage State Management Module
 * Centralized state for all collage operations
 */
const CollageState = (() => {
    const state = {
        images: [],
        selectedImage: null,
        layout: 'freeform',
        background: '#e8f4f8',
        isLocked: false,
        canvas: null,
        canvasRect: null
    };

    const listeners = [];

    function notify(key, value) {
        listeners.forEach(fn => fn(key, value));
    }

    return {
        get(key) {
            return state[key];
        },

        set(key, value) {
            state[key] = value;
            notify(key, value);
        },

        getImages() {
            return [...state.images];
        },

        addImage(imageData) {
            state.images.push(imageData);
            notify('images', state.images);
        },

        removeImage(id) {
            state.images = state.images.filter(img => img.id !== id);
            if (state.selectedImage === id) {
                state.selectedImage = null;
                notify('selectedImage', null);
            }
            notify('images', state.images);
        },

        updateImage(id, updates) {
            const img = state.images.find(i => i.id === id);
            if (img) {
                Object.assign(img, updates);
                notify('imageUpdate', { id, updates });
            }
        },

        getImageById(id) {
            return state.images.find(i => i.id === id);
        },

        selectImage(id) {
            state.selectedImage = id;
            notify('selectedImage', id);
        },

        clearSelection() {
            state.selectedImage = null;
            notify('selectedImage', null);
        },

        setCanvas(el) {
            state.canvas = el;
            state.canvasRect = el.getBoundingClientRect();
        },

        updateCanvasRect() {
            if (state.canvas) {
                state.canvasRect = state.canvas.getBoundingClientRect();
            }
        },

        getCanvasRect() {
            return state.canvasRect;
        },

        lock() {
            state.isLocked = true;
            notify('isLocked', true);
        },

        unlock() {
            state.isLocked = false;
            notify('isLocked', false);
        },

        isLocked() {
            return state.isLocked;
        },

        clearAll() {
            state.images = [];
            state.selectedImage = null;
            state.layout = 'freeform';
            state.background = '#e8f4f8';
            state.isLocked = false;
            notify('clear', null);
        },

        subscribe(fn) {
            listeners.push(fn);
            return () => {
                const idx = listeners.indexOf(fn);
                if (idx > -1) listeners.splice(idx, 1);
            };
        },

        exportData() {
            return {
                images: state.images.map(img => ({
                    x: img.x,
                    y: img.y,
                    width: img.width,
                    height: img.height,
                    rotation: img.rotation,
                    src: img.src
                })),
                background: state.background,
                layout: state.layout
            };
        }
    };
})();
