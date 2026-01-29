/**
 * Collage Cleanup Module
 * Handles Done (lock/export) and Delete functionality
 */
const CollageCleanup = (() => {
    let canvas = null;

    function done() {
        CollageState.clearSelection();

        document.querySelectorAll('.collage-item').forEach(item => {
            item.classList.remove('selected');
            item.querySelectorAll('.resize-handle, .rotate-handle').forEach(h => h.remove());
        });

        CollageState.lock();
        canvas.classList.add('locked');

        const exportData = CollageState.exportData();
        console.log('Collage exported:', exportData);

        return exportData;
    }

    function unlock() {
        CollageState.unlock();
        canvas.classList.remove('locked');
    }

    function deleteSelected() {
        const selectedId = CollageState.get('selectedImage');

        if (selectedId) {
            CollageState.removeImage(selectedId);
            CollageImages.removeImageElement(selectedId);
        } else {
            deleteAll();
        }
    }

    function deleteAll() {
        const confirmed = window.confirm('Delete entire collage?');
        if (confirmed) {
            CollageState.clearAll();
            CollageImages.clearAllImages();
        }
    }

    function init(canvasElement) {
        canvas = canvasElement;
    }

    return {
        init,
        done,
        unlock,
        deleteSelected,
        deleteAll
    };
})();
