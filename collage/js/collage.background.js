/**
 * Collage Background Module
 * Handles background color selection
 */
const CollageBackground = (() => {
    let canvas = null;
    let colorPicker = null;

    function createColorPicker() {
        colorPicker = document.createElement('input');
        colorPicker.type = 'color';
        colorPicker.className = 'collage-color-picker';
        colorPicker.value = CollageState.get('background');
        colorPicker.addEventListener('input', onColorChange);
        document.body.appendChild(colorPicker);
    }

    function onColorChange(e) {
        const color = e.target.value;
        CollageState.set('background', color);
        applyBackground(color);
    }

    function applyBackground(color) {
        if (canvas) {
            canvas.style.backgroundColor = color;
        }
    }

    function openColorPicker() {
        if (CollageState.isLocked()) return;
        if (!colorPicker) createColorPicker();
        colorPicker.click();
    }

    function init(canvasElement) {
        canvas = canvasElement;
        applyBackground(CollageState.get('background'));
    }

    function destroy() {
        if (colorPicker) {
            colorPicker.remove();
            colorPicker = null;
        }
    }

    return {
        init,
        destroy,
        openColorPicker,
        applyBackground
    };
})();
