/**
 * Collage Layouts Module
 * Handles Grid, Masonry, and Freeform layouts
 */
const CollageLayouts = (() => {
    let canvas = null;
    let layoutMenu = null;

    const LAYOUTS = {
        grid: 'Grid',
        masonry: 'Masonry',
        freeform: 'Freeform'
    };

    const SPACING = 10;

    function applyGrid() {
        const images = CollageState.getImages();
        if (images.length === 0) return;

        const canvasRect = CollageState.getCanvasRect();
        const cols = Math.ceil(Math.sqrt(images.length));
        const rows = Math.ceil(images.length / cols);

        const cellWidth = (canvasRect.width - SPACING * (cols + 1)) / cols;
        const cellHeight = (canvasRect.height - SPACING * (rows + 1)) / rows;
        const size = Math.min(cellWidth, cellHeight);

        images.forEach((img, i) => {
            const col = i % cols;
            const row = Math.floor(i / cols);
            const x = SPACING + col * (size + SPACING);
            const y = SPACING + row * (size + SPACING);

            CollageState.updateImage(img.id, {
                x, y,
                width: size,
                height: size,
                rotation: 0
            });
        });

        updateCanvasClass('layout-grid');
    }

    function applyMasonry() {
        const images = CollageState.getImages();
        if (images.length === 0) return;

        const canvasRect = CollageState.getCanvasRect();
        const cols = 3;
        const colWidth = (canvasRect.width - SPACING * (cols + 1)) / cols;
        const colHeights = new Array(cols).fill(SPACING);

        images.forEach((img) => {
            const shortestCol = colHeights.indexOf(Math.min(...colHeights));
            const x = SPACING + shortestCol * (colWidth + SPACING);
            const y = colHeights[shortestCol];

            const aspectRatio = 0.6 + Math.random() * 0.8;
            const height = colWidth * aspectRatio;

            CollageState.updateImage(img.id, {
                x, y,
                width: colWidth,
                height: height,
                rotation: 0
            });

            colHeights[shortestCol] += height + SPACING;
        });

        updateCanvasClass('layout-masonry');
    }

    function applyFreeform() {
        updateCanvasClass('layout-freeform');
    }

    function updateCanvasClass(className) {
        canvas.classList.remove('layout-grid', 'layout-masonry', 'layout-freeform');
        canvas.classList.add(className);
    }

    function applyLayout(layoutType) {
        if (CollageState.isLocked()) return;

        CollageState.set('layout', layoutType);

        switch (layoutType) {
            case 'grid':
                applyGrid();
                break;
            case 'masonry':
                applyMasonry();
                break;
            case 'freeform':
                applyFreeform();
                break;
        }

        closeLayoutMenu();
    }

    function createLayoutMenu() {
        layoutMenu = document.createElement('div');
        layoutMenu.className = 'layout-menu';
        layoutMenu.innerHTML = Object.entries(LAYOUTS).map(([key, label]) => `
            <button class="layout-option" data-layout="${key}">${label}</button>
        `).join('');

        layoutMenu.addEventListener('click', (e) => {
            const btn = e.target.closest('.layout-option');
            if (btn) {
                applyLayout(btn.dataset.layout);
            }
        });

        document.body.appendChild(layoutMenu);
    }

    function openLayoutMenu(anchorBtn) {
        if (CollageState.isLocked()) return;
        if (!layoutMenu) createLayoutMenu();

        const rect = anchorBtn.getBoundingClientRect();
        layoutMenu.style.top = `${rect.bottom + 5}px`;
        layoutMenu.style.left = `${rect.left}px`;
        layoutMenu.classList.add('visible');

        setTimeout(() => {
            document.addEventListener('click', closeOnOutsideClick);
        }, 0);
    }

    function closeLayoutMenu() {
        if (layoutMenu) {
            layoutMenu.classList.remove('visible');
        }
        document.removeEventListener('click', closeOnOutsideClick);
    }

    function closeOnOutsideClick(e) {
        if (!layoutMenu.contains(e.target)) {
            closeLayoutMenu();
        }
    }

    function init(canvasElement) {
        canvas = canvasElement;
    }

    function destroy() {
        if (layoutMenu) {
            layoutMenu.remove();
            layoutMenu = null;
        }
        document.removeEventListener('click', closeOnOutsideClick);
    }

    return {
        init,
        destroy,
        openLayoutMenu,
        applyLayout
    };
})();
