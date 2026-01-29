/**
 * Collage Rotate Module
 * Handles rotation via handle above the image
 */
const CollageRotate = (() => {
    let canvas = null;
    let isRotating = false;
    let rotateTarget = null;
    let centerX = 0;
    let centerY = 0;
    let startAngle = 0;
    let startRotation = 0;

    function getEventCoords(e) {
        if (e.touches && e.touches.length > 0) {
            return { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
        return { x: e.clientX, y: e.clientY };
    }

    function calculateAngle(cx, cy, x, y) {
        return Math.atan2(y - cy, x - cx) * (180 / Math.PI);
    }

    function onStart(e) {
        if (CollageState.isLocked()) return;

        const handle = e.target.closest('.rotate-handle');
        if (!handle) return;

        e.preventDefault();
        e.stopPropagation();

        isRotating = true;
        rotateTarget = handle.closest('.collage-item');

        const id = rotateTarget.dataset.id;
        const imageData = CollageState.getImageById(id);
        const canvasRect = CollageState.getCanvasRect();

        if (imageData) {
            centerX = canvasRect.left + imageData.x + imageData.width / 2;
            centerY = canvasRect.top + imageData.y + imageData.height / 2;
            startRotation = imageData.rotation;

            const coords = getEventCoords(e);
            startAngle = calculateAngle(centerX, centerY, coords.x, coords.y);
        }
    }

    function onMove(e) {
        if (!isRotating || !rotateTarget) return;

        e.preventDefault();
        const coords = getEventCoords(e);
        const currentAngle = calculateAngle(centerX, centerY, coords.x, coords.y);
        const deltaAngle = currentAngle - startAngle;

        let newRotation = startRotation + deltaAngle;

        while (newRotation < 0) newRotation += 360;
        while (newRotation >= 360) newRotation -= 360;

        const id = rotateTarget.dataset.id;
        CollageState.updateImage(id, { rotation: newRotation });
    }

    function onEnd() {
        isRotating = false;
        rotateTarget = null;
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
