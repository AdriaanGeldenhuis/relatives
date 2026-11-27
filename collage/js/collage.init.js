'use strict';

(() => {
    const canvas = document.getElementById('collageCanvas');
    const emptyMessage = document.getElementById('collageEmpty');
    const chooseImages = document.getElementById('btnChooseImages');
    const layout = document.getElementById('btnChooseLayout');
    const download = document.getElementById('btnDone');
    const remove = document.getElementById('btnDelete');

    CollageState.setCanvas(canvas);
    CollageImages.init(canvas);
    CollageDrag.init(canvas);
    CollageResize.init(canvas);
    CollageRotate.init(canvas);
    CollageLayouts.init(canvas);
    CollageBackground.init(canvas);
    CollageCleanup.init(canvas);

    chooseImages.addEventListener('click', CollageImages.openFilePicker);
    layout.addEventListener('click', () => CollageLayouts.openLayoutMenu(layout));
    download.addEventListener('click', CollageCleanup.done);
    remove.addEventListener('click', CollageCleanup.deleteSelected);

    function updateControls() {
        const empty = CollageState.getImages().length === 0;
        const loading = Boolean(CollageState.get('loading'));
        emptyMessage.hidden = !empty;
        chooseImages.disabled = loading;
        download.disabled = empty || loading || Boolean(CollageState.get('exporting'));
        remove.disabled = empty || loading;
        remove.textContent = CollageState.get('selectedImage') ? 'Remove photo' : 'Clear collage';
    }

    CollageState.subscribe(updateControls);
    updateControls();

    canvas.addEventListener('click', event => {
        if (event.target !== canvas && event.target !== emptyMessage) return;
        CollageState.clearSelection();
        canvas.querySelectorAll('.collage-item.selected').forEach(item => {
            item.classList.remove('selected');
            item.setAttribute('aria-pressed', 'false');
            item.querySelectorAll('.resize-handle, .rotate-handle').forEach(handle => handle.remove());
        });
    });

    window.addEventListener('resize', () => {
        const oldWidth = CollageState.getCanvasRect().width;
        CollageState.updateCanvasRect();
        const width = CollageState.getCanvasRect().width;
        if (!oldWidth || !width || width === oldWidth) return;
        const scale = width / oldWidth;
        for (const image of CollageState.getImages()) {
            CollageState.updateImage(image.id, {
                x: image.x * scale, y: image.y * scale, width: image.width * scale, height: image.height * scale
            });
        }
    });
})();
