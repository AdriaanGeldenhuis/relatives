/**
 * Collage Resize Module
 * Handles corner resize handles with optional aspect ratio lock (shift key)
 */
const CollageResize = (() => {
    let canvas = null;
    let isResizing = false;
    let resizeTarget = null;
    let handlePosition = null;
    let startX = 0;
    let startY = 0;
    let startWidth = 0;
    let startHeight = 0;
    let startLeft = 0;
    let startTop = 0;
    let aspectRatio = 1;
    let shiftPressed = false;

    const MIN_SIZE = 50;

    function getEventCoords(e) {
        if (e.touches && e.touches.length > 0) {
            return { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
        return { x: e.clientX, y: e.clientY };
    }

    function onStart(e) {
        if (CollageState.isLocked()) return;

        const handle = e.target.closest('.resize-handle');
        if (!handle) return;

        e.preventDefault();
        e.stopPropagation();

        isResizing = true;
        resizeTarget = handle.closest('.collage-item');
        handlePosition = handle.dataset.handle;

        const coords = getEventCoords(e);
        startX = coords.x;
        startY = coords.y;

        const id = resizeTarget.dataset.id;
        const imageData = CollageState.getImageById(id);
        if (imageData) {
            startWidth = imageData.width;
            startHeight = imageData.height;
            startLeft = imageData.x;
            startTop = imageData.y;
            aspectRatio = startWidth / startHeight;
        }
    }

    function onMove(e) {
        if (!isResizing || !resizeTarget) return;

        e.preventDefault();
        const coords = getEventCoords(e);
        const deltaX = coords.x - startX;
        const deltaY = coords.y - startY;

        let newWidth = startWidth;
        let newHeight = startHeight;
        let newX = startLeft;
        let newY = startTop;

        switch (handlePosition) {
            case 'se':
                newWidth = Math.max(MIN_SIZE, startWidth + deltaX);
                newHeight = Math.max(MIN_SIZE, startHeight + deltaY);
                break;
            case 'sw':
                newWidth = Math.max(MIN_SIZE, startWidth - deltaX);
                newHeight = Math.max(MIN_SIZE, startHeight + deltaY);
                newX = startLeft + (startWidth - newWidth);
                break;
            case 'ne':
                newWidth = Math.max(MIN_SIZE, startWidth + deltaX);
                newHeight = Math.max(MIN_SIZE, startHeight - deltaY);
                newY = startTop + (startHeight - newHeight);
                break;
            case 'nw':
                newWidth = Math.max(MIN_SIZE, startWidth - deltaX);
                newHeight = Math.max(MIN_SIZE, startHeight - deltaY);
                newX = startLeft + (startWidth - newWidth);
                newY = startTop + (startHeight - newHeight);
                break;
        }

        if (shiftPressed) {
            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                newHeight = newWidth / aspectRatio;
            } else {
                newWidth = newHeight * aspectRatio;
            }

            if (handlePosition === 'sw' || handlePosition === 'nw') {
                newX = startLeft + startWidth - newWidth;
            }
            if (handlePosition === 'ne' || handlePosition === 'nw') {
                newY = startTop + startHeight - newHeight;
            }
        }

        const canvasRect = CollageState.getCanvasRect();
        newX = Math.max(0, Math.min(newX, canvasRect.width - newWidth));
        newY = Math.max(0, Math.min(newY, canvasRect.height - newHeight));

        const id = resizeTarget.dataset.id;
        CollageState.updateImage(id, {
            width: newWidth,
            height: newHeight,
            x: newX,
            y: newY
        });
    }

    function onEnd() {
        isResizing = false;
        resizeTarget = null;
        handlePosition = null;
    }

    function onKeyDown(e) {
        if (e.key === 'Shift') shiftPressed = true;
    }

    function onKeyUp(e) {
        if (e.key === 'Shift') shiftPressed = false;
    }

    function init(canvasElement) {
        canvas = canvasElement;

        canvas.addEventListener('mousedown', onStart);
        canvas.addEventListener('touchstart', onStart, { passive: false });

        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });

        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('keyup', onKeyUp);
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
        document.removeEventListener('keydown', onKeyDown);
        document.removeEventListener('keyup', onKeyUp);
    }

    return { init, destroy };
})();
