/**
 * Collage Init Module
 * Main initialization and event binding
 */
const CollageInit = (() => {
    let canvas = null;
    let isInitialized = false;

    function addTouchHandler(element, handler) {
        let touchHandled = false;

        element.addEventListener('touchstart', () => {
            element.style.opacity = '0.7';
        }, { passive: true });

        element.addEventListener('touchend', (e) => {
            element.style.opacity = '';
            e.preventDefault();
            touchHandled = true;
            handler(e);
            setTimeout(() => { touchHandled = false; }, 300);
        }, { passive: false });

        element.addEventListener('touchcancel', () => {
            element.style.opacity = '';
        }, { passive: true });

        element.addEventListener('click', (e) => {
            if (!touchHandled) {
                handler(e);
            }
        });
    }

    function bindToolbarEvents() {
        const btnChooseImages = document.getElementById('btnChooseImages');
        const btnChooseLayout = document.getElementById('btnChooseLayout');
        const btnChooseBackground = document.getElementById('btnChooseBackground');
        const btnDone = document.getElementById('btnDone');
        const btnDelete = document.getElementById('btnDelete');

        if (btnChooseImages) {
            addTouchHandler(btnChooseImages, () => {
                CollageImages.openFilePicker();
            });
        }

        if (btnChooseLayout) {
            addTouchHandler(btnChooseLayout, (e) => {
                CollageLayouts.openLayoutMenu(e.currentTarget || btnChooseLayout);
            });
        }

        if (btnChooseBackground) {
            addTouchHandler(btnChooseBackground, () => {
                CollageBackground.openColorPicker();
            });
        }

        if (btnDone) {
            addTouchHandler(btnDone, () => {
                CollageCleanup.done();
            });
        }

        if (btnDelete) {
            addTouchHandler(btnDelete, () => {
                CollageCleanup.deleteSelected();
            });
        }
    }

    function bindCanvasEvents() {
        canvas.addEventListener('click', (e) => {
            if (e.target === canvas && !CollageState.isLocked()) {
                CollageState.clearSelection();
                document.querySelectorAll('.collage-item').forEach(item => {
                    item.classList.remove('selected');
                    item.querySelectorAll('.resize-handle, .rotate-handle').forEach(h => h.remove());
                });
            }
        });
    }

    function handleResize() {
        CollageState.updateCanvasRect();
    }

    function init() {
        if (isInitialized) return;

        canvas = document.getElementById('collageCanvas');
        if (!canvas) {
            console.error('Collage canvas not found');
            return;
        }

        CollageState.setCanvas(canvas);

        CollageImages.init(canvas);
        CollageDrag.init(canvas);
        CollageResize.init(canvas);
        CollageRotate.init(canvas);
        CollageLayouts.init(canvas);
        CollageBackground.init(canvas);
        CollageCleanup.init(canvas);

        bindToolbarEvents();
        bindCanvasEvents();

        window.addEventListener('resize', handleResize);

        isInitialized = true;
    }

    function destroy() {
        if (!isInitialized) return;

        CollageDrag.destroy();
        CollageResize.destroy();
        CollageRotate.destroy();
        CollageLayouts.destroy();
        CollageBackground.destroy();

        window.removeEventListener('resize', handleResize);

        isInitialized = false;
    }

    return { init, destroy };
})();
