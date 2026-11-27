/**
 * Collage Layouts Module
 * Handles Grid, Masonry, and Freeform layouts
 */
const CollageLayouts = (() => {
    let canvas = null;
    let layoutMenu = null;
    let menuButton = null;

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
        const size = Math.max(1, Math.min(cellWidth, cellHeight));

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
        const cols = Math.min(3, images.length);
        const colWidth = (canvasRect.width - SPACING * (cols + 1)) / cols;
        const colHeights = new Array(cols).fill(SPACING);

        const placements = images.map(img => {
            const shortestCol = colHeights.indexOf(Math.min(...colHeights));
            const x = SPACING + shortestCol * (colWidth + SPACING);
            const y = colHeights[shortestCol];

            const height = colWidth * Math.max(.6, Math.min(1.4, img.aspect));
            colHeights[shortestCol] += height + SPACING;
            return { img, x, y, height };
        });
        const scale = Math.min(1, canvasRect.height / Math.max(...colHeights));
        for (const { img, x, y, height } of placements) {
            CollageState.updateImage(img.id, {
                x: x * scale, y: y * scale,
                width: colWidth * scale,
                height: height * scale,
                rotation: 0
            });
        }

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

        closeLayoutMenu(true);
    }

    function createLayoutMenu() {
        layoutMenu = document.createElement('div');
        layoutMenu.className = 'layout-menu';
        layoutMenu.id = 'collageLayoutOptions';
        layoutMenu.innerHTML = Object.entries(LAYOUTS).map(([key, label]) => `
            <button class="layout-option" data-layout="${key}">${label}</button>
        `).join('');

        layoutMenu.addEventListener('click', (e) => {
            const btn = e.target.closest('.layout-option');
            if (btn) {
                applyLayout(btn.dataset.layout);
            }
        });
        layoutMenu.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeLayoutMenu(true);
        });

        document.body.appendChild(layoutMenu);
    }

    function openLayoutMenu(anchorBtn) {
        if (CollageState.isLocked()) return;
        if (!layoutMenu) createLayoutMenu();
        if (menuButton === anchorBtn) {
            closeLayoutMenu(true);
            return;
        }
        menuButton = anchorBtn;
        anchorBtn.setAttribute('aria-controls', layoutMenu.id);
        anchorBtn.setAttribute('aria-expanded', 'true');

        const rect = anchorBtn.getBoundingClientRect();
        layoutMenu.style.top = `${rect.bottom + 5}px`;
        layoutMenu.style.left = `${rect.left}px`;
        layoutMenu.classList.add('visible');
        layoutMenu.style.left = `${Math.min(rect.left, innerWidth - layoutMenu.offsetWidth - 8)}px`;
        layoutMenu.querySelector('button')?.focus();

        setTimeout(() => {
            document.addEventListener('click', closeOnOutsideClick);
        }, 0);
    }

    function closeLayoutMenu(restoreFocus = false) {
        if (layoutMenu) {
            layoutMenu.classList.remove('visible');
        }
        if (menuButton) {
            menuButton.setAttribute('aria-expanded', 'false');
            if (restoreFocus) menuButton.focus();
            menuButton = null;
        }
        document.removeEventListener('click', closeOnOutsideClick);
    }

    function closeOnOutsideClick(e) {
        if (!layoutMenu.contains(e.target) && !menuButton?.contains(e.target)) {
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
