/**
 * Collage Init Module
 * Main initialization and event binding
 */
const CollageInit = (() => {
    let canvas = null;
    let isInitialized = false;

    function bindToolbarEvents() {
        const btnChooseImages = document.getElementById('btnChooseImages');
        const btnChooseLayout = document.getElementById('btnChooseLayout');
        const btnChooseBackground = document.getElementById('btnChooseBackground');
        const btnDone = document.getElementById('btnDone');
        const btnDelete = document.getElementById('btnDelete');

        if (btnChooseImages) {
            btnChooseImages.addEventListener('click', () => {
                CollageImages.openFilePicker();
            });
        }

        if (btnChooseLayout) {
            btnChooseLayout.addEventListener('click', (e) => {
                CollageLayouts.openLayoutMenu(e.currentTarget);
            });
        }

        if (btnChooseBackground) {
            btnChooseBackground.addEventListener('click', () => {
                CollageBackground.openColorPicker();
            });
        }

        if (btnDone) {
            btnDone.addEventListener('click', () => {
                CollageCleanup.done();
            });
        }

        if (btnDelete) {
            btnDelete.addEventListener('click', () => {
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

document.addEventListener('DOMContentLoaded', CollageInit.init);
