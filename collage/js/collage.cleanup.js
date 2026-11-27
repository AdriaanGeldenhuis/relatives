'use strict';

const CollageCleanup = (() => {
    let canvas;

    async function done() {
        const photos = CollageState.getImages().map(item => ({
            ...item,
            image: canvas.querySelector(`[data-id="${item.id}"] img`)
        }));
        if (!photos.length) return;

        const status = document.getElementById('collageStatus');
        CollageState.set('exporting', true);
        try {
            const { width, height } = CollageState.getCanvasRect();
            const output = document.createElement('canvas');
            const scale = Math.min(3, 1800 / width);
            output.width = Math.round(width * scale);
            output.height = Math.round(height * scale);
            const context = output.getContext('2d');
            context.scale(scale, scale);
            context.fillStyle = CollageState.get('background');
            context.fillRect(0, 0, width, height);
            await Promise.all(photos.map(({ image }) => image.decode()));

            for (const photo of photos) {
                const border = 3;
                const innerWidth = Math.max(1, photo.width - border * 2);
                const innerHeight = Math.max(1, photo.height - border * 2);
                const zoom = Math.max(innerWidth / photo.image.naturalWidth, innerHeight / photo.image.naturalHeight);
                const imageWidth = photo.image.naturalWidth * zoom;
                const imageHeight = photo.image.naturalHeight * zoom;
                context.save();
                context.translate(photo.x + photo.width / 2, photo.y + photo.height / 2);
                context.rotate(photo.rotation * Math.PI / 180);
                context.fillStyle = '#ffffff';
                context.fillRect(-photo.width / 2, -photo.height / 2, photo.width, photo.height);
                context.beginPath();
                context.rect(-innerWidth / 2, -innerHeight / 2, innerWidth, innerHeight);
                context.clip();
                context.drawImage(photo.image, -imageWidth / 2, -imageHeight / 2, imageWidth, imageHeight);
                context.restore();
            }

            const blob = await new Promise(resolve => output.toBlob(resolve, 'image/png'));
            if (!blob) throw new Error('Image encoding failed.');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'relatives-collage.png';
            document.body.append(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 10000);
            status.textContent = 'Collage ready to download.';
        } catch {
            status.textContent = 'Could not export the collage. Try again.';
        } finally {
            CollageState.set('exporting', false);
        }
    }

    function deleteSelected() {
        const selectedId = CollageState.get('selectedImage');
        if (selectedId) {
            CollageState.removeImage(selectedId);
            CollageImages.removeImageElement(selectedId);
        } else if (window.confirm('Clear every photo from this collage?')) {
            CollageState.clearAll();
            CollageBackground.applyBackground(CollageState.get('background'));
            CollageLayouts.applyLayout('freeform');
        }
    }

    return { init: element => { canvas = element; }, done, deleteSelected };
})();
