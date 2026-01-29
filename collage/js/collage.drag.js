/**
 * Collage Drag Module
 * Handles mouse and touch dragging of images
 */
const CollageDrag = (() => {
    let canvas = null;
    let isDragging = false;
    let dragTarget = null;
    let startX = 0;
    let startY = 0;
    let startLeft = 0;
    let startTop = 0;

    function getEventCoords(e) {
        if (e.touches && e.touches.length > 0) {
            return { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
        return { x: e.clientX, y: e.clientY };
    }

    function onStart(e) {
        if (CollageState.isLocked()) return;

        const target = e.target.closest('.collage-item');
        if (!target) return;
        if (e.target.classList.contains('resize-handle') ||
            e.target.classList.contains('rotate-handle')) return;

        e.preventDefault();
        isDragging = true;
        dragTarget = target;

        const coords = getEventCoords(e);
        startX = coords.x;
        startY = coords.y;

        const id = target.dataset.id;
        const imageData = CollageState.getImageById(id);
        if (imageData) {
            startLeft = imageData.x;
            startTop = imageData.y;
        }

        target.classList.add('dragging');
    }

    function onMove(e) {
        if (!isDragging || !dragTarget) return;

        e.preventDefault();
        const coords = getEventCoords(e);
        const deltaX = coords.x - startX;
        const deltaY = coords.y - startY;

        let newX = startLeft + deltaX;
        let newY = startTop + deltaY;

        const canvasRect = CollageState.getCanvasRect();
        const id = dragTarget.dataset.id;
        const imageData = CollageState.getImageById(id);

        if (imageData) {
            const minX = 0;
            const minY = 0;
            const maxX = canvasRect.width - imageData.width;
            const maxY = canvasRect.height - imageData.height;

            newX = Math.max(minX, Math.min(newX, maxX));
            newY = Math.max(minY, Math.min(newY, maxY));

            CollageState.updateImage(id, { x: newX, y: newY });
        }
    }

    function onEnd() {
        if (dragTarget) {
            dragTarget.classList.remove('dragging');
        }
        isDragging = false;
        dragTarget = null;
    }

    function init(canvasElement) {
        canvas = canvasElement;

        canvas.addEventListener('mousedown', onStart);
        canvas.addEventListener('touchstart', onStart, { passive: false });

        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });

        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);
    }

    function destroy() {
        if (canvas) {
            canvas.removeEventListener('mousedown', onStart);
            canvas.removeEventListener('touchstart', onStart);
        }
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('mouseup', onEnd);
        document.removeEventListener('touchend', onEnd);
    }

    return { init, destroy };
})();
