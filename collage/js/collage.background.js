'use strict';

const CollageBackground = (() => {
    let canvas;
    let picker;

    function applyBackground(color) {
        canvas.style.backgroundColor = color;
        picker.value = color;
    }

    function init(canvasElement) {
        canvas = canvasElement;
        picker = document.getElementById('collageColor');
        picker.addEventListener('input', () => {
            CollageState.set('background', picker.value);
            applyBackground(picker.value);
        });
        applyBackground(CollageState.get('background'));
    }

    return { init, applyBackground };
})();
