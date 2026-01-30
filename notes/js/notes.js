/**
 * RELATIVES - NOTES JAVASCRIPT
 * Interactive sticky notes with voice recording
 */

// ============================================
// GLOBAL VARIABLES
// ============================================

let mediaRecorder = null;
let audioChunks = [];
let recordingTimer = null;
let recordingStartTime = 0;
let audioContext = null;
let analyser = null;
let dataArray = null;
let animationId = null;
let recordedBlob = null;
let currentShareNoteId = null;
let selectedNoteImages = []; // For multiple photo uploads (up to 7)
const MAX_PHOTOS = 7;

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    initParticles();
    initNoteAnimations();
    updateStats();
    initQuickActionButtons();
    initPhotoUpload();
});

// ============================================
// PHOTO UPLOAD FOR NOTES
// ============================================

function initPhotoUpload() {
    const photoInput = document.getElementById('notePhotoInput');
    const dropZone = document.getElementById('notePhotoDropZone');

    if (photoInput) {
        photoInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                handlePhotosSelect(Array.from(this.files));
            }
        });
    }

    if (dropZone) {
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');

            const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
            if (files.length > 0) {
                handlePhotosSelect(files);
            }
        });
    }
}

function handlePhotosSelect(files) {
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const maxSize = 10 * 1024 * 1024; // 10MB

    // Filter valid files
    const validFiles = files.filter(file => {
        if (!allowedTypes.includes(file.type)) {
            return false;
        }
        if (file.size > maxSize) {
            showToast(`${file.name} is too large (max 10MB)`, 'error');
            return false;
        }
        return true;
    });

    // Check if adding would exceed max
    const remainingSlots = MAX_PHOTOS - selectedNoteImages.length;
    if (remainingSlots <= 0) {
        showToast(`Maximum ${MAX_PHOTOS} photos allowed`, 'warning');
        return;
    }

    const filesToAdd = validFiles.slice(0, remainingSlots);
    if (filesToAdd.length < validFiles.length) {
        showToast(`Only ${filesToAdd.length} photos added (max ${MAX_PHOTOS})`, 'warning');
    }

    // Add files to selection
    selectedNoteImages = [...selectedNoteImages, ...filesToAdd];
    updatePhotoPreviewsUI();
}

function updatePhotoPreviewsUI() {
    const container = document.getElementById('notePhotoPreviewsContainer');
    const grid = document.getElementById('notePhotoPreviewsGrid');
    const dropZone = document.getElementById('notePhotoDropZone');
    const countText = document.getElementById('photoCountText');

    if (selectedNoteImages.length === 0) {
        container.style.display = 'none';
        dropZone.style.display = '';
        return;
    }

    // Show container, hide dropzone if max reached
    container.style.display = 'block';
    dropZone.style.display = selectedNoteImages.length >= MAX_PHOTOS ? 'none' : '';
    countText.textContent = selectedNoteImages.length;

    // Clear and rebuild preview grid
    grid.innerHTML = '';

    selectedNoteImages.forEach((file, index) => {
        const previewItem = document.createElement('div');
        previewItem.className = 'photo-preview-item';

        const img = document.createElement('img');
        img.alt = `Preview ${index + 1}`;

        const reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'photo-preview-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.onclick = function() {
            removeNotePhoto(index);
        };

        previewItem.appendChild(img);
        previewItem.appendChild(removeBtn);
        grid.appendChild(previewItem);
    });

    // Add "add more" button if not at max
    if (selectedNoteImages.length < MAX_PHOTOS) {
        const addMoreBtn = document.createElement('div');
        addMoreBtn.className = 'photo-preview-add-more';
        addMoreBtn.innerHTML = '<span>+</span>';
        addMoreBtn.onclick = function() {
            document.getElementById('notePhotoInput').click();
        };
        grid.appendChild(addMoreBtn);
    }
}

function removeNotePhoto(index) {
    if (typeof index === 'number') {
        // Remove specific photo
        selectedNoteImages.splice(index, 1);
    } else {
        // Clear all photos
        selectedNoteImages = [];
    }
    updatePhotoPreviewsUI();
    document.getElementById('notePhotoInput').value = '';
}

function clearAllNotePhotos() {
    selectedNoteImages = [];
    updatePhotoPreviewsUI();
    document.getElementById('notePhotoInput').value = '';
}

function openImageFullscreen(imagePath) {
    // Create fullscreen overlay
    const overlay = document.createElement('div');
    overlay.className = 'image-fullscreen-overlay';
    overlay.innerHTML = `
        <button class="image-fullscreen-close" onclick="this.parentElement.remove()">&times;</button>
        <img src="${escapeHtml(imagePath)}" alt="Full size image">
    `;

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.remove();
        }
    });

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    // Close on escape
    const handleEscape = function(e) {
        if (e.key === 'Escape') {
            overlay.remove();
            document.body.style.overflow = '';
            document.removeEventListener('keydown', handleEscape);
        }
    };
    document.addEventListener('keydown', handleEscape);

    // Restore scroll when overlay is removed
    const observer = new MutationObserver(function(mutations) {
        if (!document.body.contains(overlay)) {
            document.body.style.overflow = '';
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true });
}

// Image carousel for multiple photos
function openImageCarousel(images, startIndex = 0) {
    if (!images || images.length === 0) return;

    // Handle JSON string input
    if (typeof images === 'string') {
        try {
            images = JSON.parse(images);
        } catch (e) {
            images = [images];
        }
    }

    // Single image - use simple fullscreen
    if (images.length === 1) {
        openImageFullscreen(images[0]);
        return;
    }

    let currentIndex = startIndex;

    const overlay = document.createElement('div');
    overlay.className = 'image-carousel-overlay';
    overlay.innerHTML = `
        <button class="carousel-close-btn" onclick="this.parentElement.remove()">&times;</button>
        <button class="carousel-nav-btn carousel-prev" aria-label="Previous">‹</button>
        <div class="carousel-image-container">
            <img src="${escapeHtml(images[currentIndex])}" alt="Photo ${currentIndex + 1}">
        </div>
        <button class="carousel-nav-btn carousel-next" aria-label="Next">›</button>
        <div class="carousel-counter">${currentIndex + 1} / ${images.length}</div>
        <div class="carousel-dots">
            ${images.map((_, i) => `<span class="carousel-dot${i === currentIndex ? ' active' : ''}" data-index="${i}"></span>`).join('')}
        </div>
    `;

    const imgEl = overlay.querySelector('.carousel-image-container img');
    const counterEl = overlay.querySelector('.carousel-counter');
    const dotsEl = overlay.querySelectorAll('.carousel-dot');
    const prevBtn = overlay.querySelector('.carousel-prev');
    const nextBtn = overlay.querySelector('.carousel-next');

    function updateImage(newIndex) {
        currentIndex = newIndex;
        imgEl.src = images[currentIndex];
        counterEl.textContent = `${currentIndex + 1} / ${images.length}`;

        dotsEl.forEach((dot, i) => {
            dot.classList.toggle('active', i === currentIndex);
        });
    }

    function goNext() {
        updateImage((currentIndex + 1) % images.length);
    }

    function goPrev() {
        updateImage((currentIndex - 1 + images.length) % images.length);
    }

    prevBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        goPrev();
    });

    nextBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        goNext();
    });

    // Dot navigation
    dotsEl.forEach(dot => {
        dot.addEventListener('click', function(e) {
            e.stopPropagation();
            updateImage(parseInt(this.dataset.index));
        });
    });

    // Close on backdrop click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay || e.target.classList.contains('carousel-image-container')) {
            overlay.remove();
            document.body.style.overflow = '';
        }
    });

    // Swipe support for mobile
    let touchStartX = 0;
    let touchEndX = 0;

    overlay.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });

    overlay.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > 50) {
            if (diff > 0) {
                goNext();
            } else {
                goPrev();
            }
        }
    }, { passive: true });

    // Keyboard navigation
    const handleKeydown = function(e) {
        if (e.key === 'Escape') {
            overlay.remove();
            document.body.style.overflow = '';
            document.removeEventListener('keydown', handleKeydown);
        } else if (e.key === 'ArrowLeft') {
            goPrev();
        } else if (e.key === 'ArrowRight') {
            goNext();
        }
    };
    document.addEventListener('keydown', handleKeydown);

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    // Cleanup when removed
    const observer = new MutationObserver(function() {
        if (!document.body.contains(overlay)) {
            document.body.style.overflow = '';
            document.removeEventListener('keydown', handleKeydown);
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true });
}

// Helper to parse image paths (JSON array or single path)
function parseImagePaths(imagePath) {
    if (!imagePath) return [];

    if (typeof imagePath === 'string') {
        try {
            const parsed = JSON.parse(imagePath);
            if (Array.isArray(parsed)) return parsed;
        } catch (e) {
            // Not JSON, treat as single path
        }
        return [imagePath];
    }

    if (Array.isArray(imagePath)) return imagePath;
    return [];
}

// ============================================
// QUICK ACTION BUTTONS - NATIVE APP FIX
// ============================================

function initQuickActionButtons() {
    // Find all quick action buttons and attach proper event listeners
    // This fixes the issue where inline onclick doesn't work in native Android WebView apps

    const quickActionBtns = document.querySelectorAll('.quick-action-btn');

    quickActionBtns.forEach(btn => {
        // Get the onclick attribute to determine the action
        const onclickAttr = btn.getAttribute('onclick');

        if (onclickAttr) {
            // Remove the inline onclick to prevent double-firing
            btn.removeAttribute('onclick');

            // Create handler based on the original onclick content
            const handler = function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Parse and execute the original onclick action
                if (onclickAttr.includes("showCreateNoteModal('text')")) {
                    showCreateNoteModal('text');
                } else if (onclickAttr.includes("showCreateNoteModal('voice')")) {
                    showCreateNoteModal('voice');
                } else if (onclickAttr.includes('searchInput')) {
                    document.getElementById('searchInput').focus();
                }
            };

            // Add both click and touch events for maximum compatibility
            btn.addEventListener('click', handler);

            // Add touchstart for visual feedback
            btn.addEventListener('touchstart', function(e) {
                btn.classList.add('touched');
            }, { passive: true });

            // Add touchend for native app support (touchstart can cause issues with scrolling)
            btn.addEventListener('touchend', function(e) {
                btn.classList.remove('touched');
                // Prevent the click event from also firing
                e.preventDefault();
                handler(e);
            }, { passive: false });

            // Remove touched class if touch is cancelled
            btn.addEventListener('touchcancel', function() {
                btn.classList.remove('touched');
            }, { passive: true });
        }
    });

    console.log('✅ Quick action buttons initialized for native app compatibility');

    // Also fix recording control buttons for native app
    initRecordingButtons();
}

// Initialize recording buttons with proper event listeners
function initRecordingButtons() {
    const startBtn = document.getElementById('startRecordBtn');
    const stopBtn = document.getElementById('stopRecordBtn');
    const playBtn = document.getElementById('playRecordBtn');

    if (startBtn) {
        startBtn.removeAttribute('onclick');
        addTouchAndClickHandler(startBtn, startRecording);
    }

    if (stopBtn) {
        stopBtn.removeAttribute('onclick');
        addTouchAndClickHandler(stopBtn, stopRecording);
    }

    if (playBtn) {
        playBtn.removeAttribute('onclick');
        addTouchAndClickHandler(playBtn, playRecording);
    }

    console.log('✅ Recording buttons initialized for native app compatibility');
}

// Helper function to add both touch and click handlers
function addTouchAndClickHandler(element, handler) {
    let touchHandled = false;

    element.addEventListener('touchstart', function() {
        element.classList.add('touched');
    }, { passive: true });

    element.addEventListener('touchend', function(e) {
        element.classList.remove('touched');
        e.preventDefault();
        touchHandled = true;
        handler();
        // Reset flag after a short delay
        setTimeout(() => { touchHandled = false; }, 300);
    }, { passive: false });

    element.addEventListener('touchcancel', function() {
        element.classList.remove('touched');
    }, { passive: true });

    element.addEventListener('click', function(e) {
        // Only handle if not already handled by touch
        if (!touchHandled) {
            handler();
        }
    });
}

// ============================================
// PARTICLES BACKGROUND - DISABLED FOR PERFORMANCE
// Canvas hidden via CSS
// ============================================

function initParticles() {
    // Disabled - canvas hidden via CSS for performance
}

// ============================================
// NOTE ANIMATIONS
// ============================================

function initNoteAnimations() {
    const cards = document.querySelectorAll('.note-card');
    
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.05}s`;
    });
}

// ============================================
// MODAL CONTROLS
// ============================================

function showCreateNoteModal(type) {
    if (type === 'text') {
        document.getElementById('createTextNoteModal').classList.add('active');
        document.getElementById('noteBody').focus();
    } else if (type === 'voice') {
        document.getElementById('createVoiceNoteModal').classList.add('active');
        resetVoiceRecorder();
    }
}

function openCollageModal() {
    document.getElementById('collageModal').classList.add('active');
    if (typeof CollageInit !== 'undefined') {
        CollageInit.init();
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('active');

    // Reset forms
    if (modalId === 'createTextNoteModal') {
        document.getElementById('noteTitle').value = '';
        document.getElementById('noteBody').value = '';
        document.querySelector('input[name="noteColor"]:checked').checked = false;
        document.getElementById('color1').checked = true;

        // Reset photo uploads
        clearAllNotePhotos();
    } else if (modalId === 'createVoiceNoteModal') {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
        resetVoiceRecorder();
    } else if (modalId === 'editNoteModal') {
        document.getElementById('editNoteId').value = '';
        document.getElementById('editNoteTitle').value = '';
        document.getElementById('editNoteBody').value = '';
    } else if (modalId === 'collageModal') {
        if (typeof CollageState !== 'undefined') {
            CollageState.clearAll();
        }
        if (typeof CollageImages !== 'undefined') {
            CollageImages.clearAllImages();
        }
    }
}

// Close modal on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        closeModal(e.target.id);
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const activeModal = document.querySelector('.modal.active');
        if (activeModal) {
            closeModal(activeModal.id);
        }
    }
});

// ============================================
// CREATE NOTE
// ============================================

async function createNote(event, type) {
    event.preventDefault();

    const formData = new FormData();
    formData.append('action', 'create_note');
    formData.append('type', type);

    if (type === 'text') {
        const title = document.getElementById('noteTitle').value.trim();
        const body = document.getElementById('noteBody').value.trim();
        const color = document.querySelector('input[name="noteColor"]:checked').value;

        // Allow notes with just images (no body text required)
        if (!body && selectedNoteImages.length === 0) {
            showToast('Please enter note content or add a photo', 'error');
            return;
        }

        formData.append('title', title);
        formData.append('body', body);
        formData.append('color', color);

        // Add images if selected (use images[] for multiple)
        selectedNoteImages.forEach((file, index) => {
            formData.append('images[]', file);
        });

    } else if (type === 'voice') {
        if (!recordedBlob) {
            showToast('Please record audio first', 'error');
            return;
        }
        
        const title = document.getElementById('voiceNoteTitle').value.trim();
        const color = document.querySelector('input[name="voiceNoteColor"]:checked').value;
        
        formData.append('title', title);
        formData.append('body', 'Voice note');
        formData.append('color', color);
        formData.append('audio', recordedBlob, 'voice-note.webm');
    }
    
    try {
        const response = await fetch('/notes/', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Note created successfully!', 'success');
            closeModal(type === 'text' ? 'createTextNoteModal' : 'createVoiceNoteModal');

            // Add note to DOM in real-time
            addNoteToDOM(data.note);
            updateStats();
        } else {
            showToast(data.error || 'Failed to create note', 'error');
        }
    } catch (error) {
        console.error('Error creating note:', error);
        showToast('Network error. Please try again.', 'error');
    }
}

// ============================================
// EDIT NOTE
// ============================================

function editNote(noteId) {
    const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
    if (!noteCard) return;
    
    const title = noteCard.querySelector('.note-title')?.textContent || '';
    const body = noteCard.querySelector('.note-body')?.textContent || '';
    const color = noteCard.style.background;
    
    document.getElementById('editNoteId').value = noteId;
    document.getElementById('editNoteTitle').value = title;
    document.getElementById('editNoteBody').value = body;
    
    // Set color
    const colorInputs = document.querySelectorAll('input[name="editNoteColor"]');
    colorInputs.forEach(input => {
        if (input.value === color) {
            input.checked = true;
        }
    });
    
    document.getElementById('editNoteModal').classList.add('active');
    document.getElementById('editNoteBody').focus();
}

async function updateNote(event) {
    event.preventDefault();
    
    const noteId = document.getElementById('editNoteId').value;
    const title = document.getElementById('editNoteTitle').value.trim();
    const body = document.getElementById('editNoteBody').value.trim();
    const color = document.querySelector('input[name="editNoteColor"]:checked').value;
    
    if (!body) {
        showToast('Please enter note content', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_note');
    formData.append('note_id', noteId);
    formData.append('title', title);
    formData.append('body', body);
    formData.append('color', color);
    
    try {
        const response = await fetch('/notes/', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Note updated successfully!', 'success');
            closeModal('editNoteModal');
            
            // Update the note card
            const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
            if (noteCard) {
                if (title) {
                    let titleEl = noteCard.querySelector('.note-title');
                    if (!titleEl) {
                        titleEl = document.createElement('div');
                        titleEl.className = 'note-title';
                        noteCard.querySelector('.note-header').after(titleEl);
                    }
                    titleEl.textContent = title;
                }
                
                const bodyEl = noteCard.querySelector('.note-body');
                if (bodyEl) {
                    bodyEl.innerHTML = body.replace(/\n/g, '<br>');
                }
                
                noteCard.style.background = color;
            }
        } else {
            showToast(data.error || 'Failed to update note', 'error');
        }
    } catch (error) {
        console.error('Error updating note:', error);
        showToast('Network error. Please try again.', 'error');
    }
}

// ============================================
// DELETE NOTE
// ============================================

async function deleteNote(noteId) {
    if (!confirm('Are you sure you want to delete this note?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_note');
    formData.append('note_id', noteId);
    
    try {
        const response = await fetch('/notes/', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('Note deleted successfully', 'success');

            // Animate out and remove
            const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
            if (noteCard) {
                noteCard.style.animation = 'noteDisappear 0.3s ease forwards';
                setTimeout(() => {
                    noteCard.remove();
                    updateStats();

                    // Check if no notes left - show empty state
                    const allCards = document.querySelectorAll('.note-card');
                    if (allCards.length === 0) {
                        showEmptyState();
                    }
                }, 300);
            }
        } else {
            showToast(data.error || 'Failed to delete note', 'error');
        }
    } catch (error) {
        console.error('Error deleting note:', error);
        showToast('Network error. Please try again.', 'error');
    }
}

// ============================================
// TOGGLE PIN
// ============================================

async function togglePin(noteId) {
    const formData = new FormData();
    formData.append('action', 'toggle_pin');
    formData.append('note_id', noteId);

    try {
        const response = await fetch('/notes/', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Update pin state in DOM
            const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
            if (noteCard) {
                const pinBtn = noteCard.querySelector('.note-pin');
                if (data.pinned) {
                    pinBtn.classList.add('active');
                    showToast('Note pinned!', 'success');
                } else {
                    pinBtn.classList.remove('active');
                    showToast('Note unpinned', 'info');
                }
                updateStats();
            }
        } else {
            showToast(data.error || 'Failed to toggle pin', 'error');
        }
    } catch (error) {
        console.error('Error toggling pin:', error);
        showToast('Network error. Please try again.', 'error');
    }
}

// ============================================
// DUPLICATE NOTE
// ============================================

function duplicateNote(noteId) {
    const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
    if (!noteCard) return;
    
    const noteType = noteCard.dataset.noteType;
    
    if (noteType === 'voice') {
        showToast('Voice notes cannot be duplicated', 'warning');
        return;
    }
    
    const title = noteCard.querySelector('.note-title')?.textContent || '';
    const body = noteCard.querySelector('.note-body')?.textContent || '';
    const color = noteCard.style.background;
    
    // Pre-fill create modal
    document.getElementById('noteTitle').value = title + ' (Copy)';
    document.getElementById('noteBody').value = body;
    
    // Set color
    const colorInputs = document.querySelectorAll('input[name="noteColor"]');
    colorInputs.forEach(input => {
        if (input.value === color) {
            input.checked = true;
        }
    });
    
    showCreateNoteModal('text');
    showToast('Note duplicated! Ready to save.', 'info');
}

// ============================================
// SHARE NOTE
// ============================================

function shareNote(noteId) {
    currentShareNoteId = noteId;
    document.getElementById('shareNoteModal').classList.add('active');
}

function copyNoteText() {
    const noteCard = document.querySelector(`[data-note-id="${currentShareNoteId}"]`);
    if (!noteCard) return;
    
    const title = noteCard.querySelector('.note-title')?.textContent || 'Untitled Note';
    const body = noteCard.querySelector('.note-body')?.textContent || '';
    
    const text = `${title}\n\n${body}`;
    
    navigator.clipboard.writeText(text).then(() => {
        showToast('Note copied to clipboard!', 'success');
        closeModal('shareNoteModal');
    }).catch(err => {
        showToast('Failed to copy note', 'error');
    });
}

function downloadNoteAsText() {
    const noteCard = document.querySelector(`[data-note-id="${currentShareNoteId}"]`);
    if (!noteCard) return;
    
    const title = noteCard.querySelector('.note-title')?.textContent || 'Untitled Note';
    const body = noteCard.querySelector('.note-body')?.textContent || '';
    
    const text = `${title}\n\n${body}`;
    
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${title.replace(/[^a-z0-9]/gi, '_')}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    showToast('Note downloaded!', 'success');
    closeModal('shareNoteModal');
}

function printNote() {
    const noteCard = document.querySelector(`[data-note-id="${currentShareNoteId}"]`);
    if (!noteCard) return;
    
    const title = noteCard.querySelector('.note-title')?.textContent || 'Untitled Note';
    const body = noteCard.querySelector('.note-body')?.innerHTML || '';
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${title}</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    padding: 40px;
                    max-width: 800px;
                    margin: 0 auto;
                }
                h1 {
                    color: #333;
                    margin-bottom: 20px;
                }
                .content {
                    line-height: 1.6;
                    color: #555;
                }
            </style>
        </head>
        <body>
            <h1>${title}</h1>
            <div class="content">${body}</div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
    
    closeModal('shareNoteModal');
}

function emailNote() {
    const noteCard = document.querySelector(`[data-note-id="${currentShareNoteId}"]`);
    if (!noteCard) return;
    
    const title = noteCard.querySelector('.note-title')?.textContent || 'Untitled Note';
    const body = noteCard.querySelector('.note-body')?.textContent || '';
    
    const subject = encodeURIComponent(`Family Note: ${title}`);
    const bodyText = encodeURIComponent(`${title}\n\n${body}`);
    
    window.location.href = `mailto:?subject=${subject}&body=${bodyText}`;
    
    closeModal('shareNoteModal');
}

// ============================================
// SEARCH NOTES
// ============================================

function searchNotes(query) {
    const searchTerm = query.toLowerCase().trim();
    const noteCards = document.querySelectorAll('.note-card');
    
    let visibleCount = 0;
    
    noteCards.forEach(card => {
        const title = card.querySelector('.note-title')?.textContent.toLowerCase() || '';
        const body = card.querySelector('.note-body')?.textContent.toLowerCase() || '';
        const author = card.querySelector('.note-author span')?.textContent.toLowerCase() || '';
        
        const matches = title.includes(searchTerm) || 
                       body.includes(searchTerm) || 
                       author.includes(searchTerm);
        
        if (matches || searchTerm === '') {
            card.style.display = '';
            card.style.animation = 'noteAppear 0.3s ease backwards';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Update total count
    if (searchTerm !== '') {
        document.getElementById('totalNotes').textContent = visibleCount;
    } else {
        updateStats();
    }
}

// ============================================
// FILTER NOTES
// ============================================

function filterNotes(filterType) {
    // Update active button
    const filterButtons = document.querySelectorAll('.filter-btn');
    filterButtons.forEach(btn => {
        if (btn.dataset.filter === filterType) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    const noteCards = document.querySelectorAll('.note-card');
    let visibleCount = 0;
    
    noteCards.forEach(card => {
        const noteType = card.dataset.noteType;
        const isPinned = card.querySelector('.note-pin.active') !== null;
        
        let show = false;
        
        switch(filterType) {
            case 'all':
                show = true;
                break;
            case 'text':
                show = noteType === 'text';
                break;
            case 'voice':
                show = noteType === 'voice';
                break;
            case 'pinned':
                show = isPinned;
                break;
        }
        
        if (show) {
            card.style.display = '';
            card.style.animation = 'noteAppear 0.3s ease backwards';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Update total count for filter
    if (filterType !== 'all') {
        document.getElementById('totalNotes').textContent = visibleCount;
    } else {
        updateStats();
    }
}

// ============================================
// UPDATE STATS
// ============================================

function updateStats() {
    const allNotes = document.querySelectorAll('.note-card');
    const pinnedNotes = document.querySelectorAll('.note-pin.active');
    const textNotes = document.querySelectorAll('[data-note-type="text"]');
    const voiceNotes = document.querySelectorAll('[data-note-type="voice"]');
    
    document.getElementById('totalNotes').textContent = allNotes.length;
    document.getElementById('pinnedCount').textContent = pinnedNotes.length;
    document.getElementById('textCount').textContent = textNotes.length;
    document.getElementById('voiceCount').textContent = voiceNotes.length;
}

// ============================================
// VOICE RECORDING
// ============================================

function resetVoiceRecorder() {
    document.getElementById('startRecordBtn').style.display = 'inline-flex';
    document.getElementById('stopRecordBtn').style.display = 'none';
    document.getElementById('playRecordBtn').style.display = 'none';
    document.getElementById('recordedAudio').style.display = 'none';
    document.getElementById('voiceNoteForm').style.display = 'none';
    
    document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '🎤';
    document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Press record to start';
    document.getElementById('recordingTimer').textContent = '00:00';
    
    audioChunks = [];
    recordedBlob = null;
    
    if (animationId) {
        cancelAnimationFrame(animationId);
    }
    
    const canvas = document.getElementById('visualizerCanvas');
    if (canvas) {
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
}

// Detect native app environment
const isNativeApp = navigator.userAgent.includes('RelativesAndroidApp') ||
                    window.matchMedia('(display-mode: standalone)').matches ||
                    window.navigator.standalone ||
                    window.AndroidInterface !== undefined ||
                    window.Android !== undefined;

async function startRecording() {
    try {
        let stream;

        // Check for native app audio recording interface
        if (isNativeApp && window.AndroidRecorder && typeof window.AndroidRecorder.startRecording === 'function') {
            // Use native Android recording
            try {
                window.AndroidRecorder.startRecording();
                updateUIForRecording();
                return;
            } catch (nativeError) {
                console.warn('Native recording failed, falling back to web API:', nativeError);
            }
        }

        // Request microphone permission - handle native WebView quirks
        if (isNativeApp && window.Android && typeof window.Android.requestMicrophonePermission === 'function') {
            // Request permission through native bridge
            const permissionGranted = await new Promise((resolve) => {
                window.onMicrophonePermissionResult = (granted) => {
                    resolve(granted);
                };
                window.Android.requestMicrophonePermission();
                // Timeout fallback
                setTimeout(() => resolve(false), 5000);
            });

            if (!permissionGranted) {
                showToast('Microphone permission denied. Please allow microphone access in app settings.', 'error');
                return;
            }
        }

        // Standard Web API
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });

        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];

        mediaRecorder.ondataavailable = (event) => {
            audioChunks.push(event.data);
        };

        mediaRecorder.onstop = () => {
            recordedBlob = new Blob(audioChunks, { type: 'audio/webm' });
            const audioUrl = URL.createObjectURL(recordedBlob);

            const audio = document.getElementById('recordedAudio');
            audio.src = audioUrl;
            audio.style.display = 'block';

            document.getElementById('playRecordBtn').style.display = 'inline-flex';
            document.getElementById('voiceNoteForm').style.display = 'block';

            // Stop all tracks
            stream.getTracks().forEach(track => track.stop());

            if (animationId) {
                cancelAnimationFrame(animationId);
            }

            const canvas = document.getElementById('visualizerCanvas');
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        };

        mediaRecorder.start();
        updateUIForRecording();

        // Start timer
        recordingStartTime = Date.now();
        recordingTimer = setInterval(updateRecordingTimer, 100);

        // Start visualizer
        setupVisualizer(stream);

    } catch (error) {
        console.error('Error accessing microphone:', error);

        let errorMessage = 'Could not access microphone.';

        if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
            errorMessage = 'No microphone found. Please connect a microphone and try again.';
        } else if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            if (isNativeApp) {
                errorMessage = 'Microphone permission denied. Please allow microphone access in your device settings for this app.';
            } else {
                errorMessage = 'Microphone permission denied. Please allow microphone access in your browser settings.';
            }
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            errorMessage = 'Microphone is in use by another application. Please close other apps using the microphone.';
        } else if (error.name === 'OverconstrainedError') {
            errorMessage = 'No suitable microphone found for the requested settings.';
        } else if (error.name === 'TypeError') {
            errorMessage = 'Your browser does not support audio recording.';
        } else if (error.name === 'AbortError' || error.name === 'SecurityError') {
            if (isNativeApp) {
                errorMessage = 'Recording not available. Please check app permissions in your device settings.';
            } else {
                errorMessage = 'Recording blocked due to security settings. Please use HTTPS.';
            }
        }

        showToast(errorMessage, 'error');
    }
}

function updateUIForRecording() {
    document.getElementById('startRecordBtn').style.display = 'none';
    document.getElementById('stopRecordBtn').style.display = 'inline-flex';
    document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '⏺️';
    document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Recording...';
}

// Callback for native app to provide recorded audio
window.onNativeRecordingComplete = function(base64Audio) {
    try {
        // Convert base64 to blob
        const byteCharacters = atob(base64Audio);
        const byteNumbers = new Array(byteCharacters.length);
        for (let i = 0; i < byteCharacters.length; i++) {
            byteNumbers[i] = byteCharacters.charCodeAt(i);
        }
        const byteArray = new Uint8Array(byteNumbers);
        recordedBlob = new Blob([byteArray], { type: 'audio/webm' });

        const audioUrl = URL.createObjectURL(recordedBlob);
        const audio = document.getElementById('recordedAudio');
        audio.src = audioUrl;
        audio.style.display = 'block';

        document.getElementById('playRecordBtn').style.display = 'inline-flex';
        document.getElementById('voiceNoteForm').style.display = 'block';
        document.getElementById('stopRecordBtn').style.display = 'none';
        document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '✅';
        document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Recording complete';

        clearInterval(recordingTimer);
    } catch (error) {
        console.error('Error processing native recording:', error);
        showToast('Failed to process recording', 'error');
    }
};

// Callback for native recording error
window.onNativeRecordingError = function(errorMessage) {
    showToast(errorMessage || 'Recording failed', 'error');
    resetVoiceRecorder();
};

function stopRecording() {
    // Handle native app recording
    if (isNativeApp && window.AndroidRecorder && typeof window.AndroidRecorder.stopRecording === 'function') {
        try {
            window.AndroidRecorder.stopRecording();
        } catch (e) {
            console.error('Native stopRecording failed:', e);
        }
    }

    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
    }

    // Stop timer
    clearInterval(recordingTimer);

    // Update UI
    document.getElementById('stopRecordBtn').style.display = 'none';
    document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '✅';
    document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Recording complete';
}

function playRecording() {
    const audio = document.getElementById('recordedAudio');
    if (audio.paused) {
        audio.play();
    } else {
        audio.pause();
    }
}

function updateRecordingTimer() {
    const elapsed = Date.now() - recordingStartTime;
    const minutes = Math.floor(elapsed / 60000);
    const seconds = Math.floor((elapsed % 60000) / 1000);
    
    document.getElementById('recordingTimer').textContent = 
        `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

function setupVisualizer(stream) {
    audioContext = new (window.AudioContext || window.webkitAudioContext)();
    analyser = audioContext.createAnalyser();
    const source = audioContext.createMediaStreamSource(stream);
    
    source.connect(analyser);
    analyser.fftSize = 256;
    
    const bufferLength = analyser.frequencyBinCount;
    dataArray = new Uint8Array(bufferLength);
    
    const canvas = document.getElementById('visualizerCanvas');
    const ctx = canvas.getContext('2d');
    
    canvas.width = canvas.offsetWidth;
    canvas.height = canvas.offsetHeight;
    
    function draw() {
        animationId = requestAnimationFrame(draw);
        
        analyser.getByteFrequencyData(dataArray);
        
        ctx.fillStyle = 'rgba(15, 12, 41, 0.2)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        const barWidth = (canvas.width / bufferLength) * 2.5;
        let x = 0;
        
        for (let i = 0; i < bufferLength; i++) {
            const barHeight = (dataArray[i] / 255) * canvas.height * 0.8;
            
            const gradient = ctx.createLinearGradient(0, canvas.height - barHeight, 0, canvas.height);
            gradient.addColorStop(0, '#667eea');
            gradient.addColorStop(0.5, '#764ba2');
            gradient.addColorStop(1, '#f093fb');
            
            ctx.fillStyle = gradient;
            ctx.fillRect(x, canvas.height - barHeight, barWidth, barHeight);
            
            x += barWidth + 1;
        }
    }
    
    draw();
}

// ============================================
// FULLSCREEN NOTE VIEW
// ============================================

let currentFullscreenNoteId = null;

function openFullscreenNote(noteId) {
    const noteCard = document.querySelector(`[data-note-id="${noteId}"]`);
    if (!noteCard) return;

    currentFullscreenNoteId = noteId;

    const noteType = noteCard.dataset.noteType;
    const title = noteCard.querySelector('.note-title')?.textContent || '';
    const body = noteCard.querySelector('.note-body')?.innerHTML || '';
    const author = noteCard.querySelector('.note-author span')?.textContent || '';
    const avatarEl = noteCard.querySelector('.author-avatar-mini');
    const avatarColor = avatarEl?.style.background || '#667eea';
    const avatarInitial = avatarEl?.textContent?.trim() || '?';
    const date = noteCard.querySelector('.note-date')?.textContent || '';
    const isPinned = noteCard.querySelector('.note-pin.active') !== null;
    const audioEl = noteCard.querySelector('.note-voice audio');
    const audioSrc = audioEl?.querySelector('source')?.src || audioEl?.src || '';
    const noteColor = noteCard.style.background;

    // Get images from carousel if present
    const carouselEl = noteCard.querySelector('.note-images-carousel');
    let imagePaths = [];
    if (carouselEl && carouselEl.dataset.images) {
        try {
            imagePaths = JSON.parse(carouselEl.dataset.images);
        } catch (e) {}
    }

    let contentHtml = '';

    if (noteType === 'voice') {
        contentHtml = `
            <div class="fullscreen-note-voice">
                <div class="fullscreen-voice-icon">🎤</div>
                <div style="font-size: 1.2rem; color: rgba(255,255,255,0.8);">Voice Note</div>
                ${audioSrc ? `<audio controls autoplay style="width: 100%; max-width: 400px;"><source src="${audioSrc}" type="audio/webm"></audio>` : '<div style="color: rgba(255,255,255,0.5);">Audio not available</div>'}
            </div>
        `;
    } else {
        // Build images carousel for fullscreen view
        let imagesHtml = '';
        if (imagePaths.length > 0) {
            const imagesJson = JSON.stringify(imagePaths).replace(/"/g, '&quot;');
            imagesHtml = `
                <div class="fullscreen-note-images" onclick="openImageCarousel(${imagesJson}, 0)">
                    <img src="${escapeHtml(imagePaths[0])}" alt="Note photo">
                    ${imagePaths.length > 1 ? `<div class="fullscreen-images-indicator">${imagePaths.length} photos - tap to view</div>` : ''}
                </div>
            `;
        }

        contentHtml = `
            ${title ? `<div class="fullscreen-note-title">${escapeHtml(title)}</div>` : ''}
            <div class="fullscreen-note-body">${body}</div>
            ${imagesHtml}
        `;
    }

    const fullContent = `
        <div style="background: ${noteColor}; border-radius: var(--radius-lg); padding: 4px; margin: -4px;">
            ${contentHtml}
        </div>
        <div class="fullscreen-note-meta">
            <div class="fullscreen-note-author">
                <div class="fullscreen-author-avatar" style="background: ${avatarColor}">${avatarInitial}</div>
                <span>${escapeHtml(author)}</span>
            </div>
            <div class="fullscreen-note-date">${escapeHtml(date)}</div>
        </div>
    `;

    document.getElementById('fullscreenNoteContent').innerHTML = fullContent;

    // Build action buttons
    const editBtn = noteType === 'text' ? `
        <button onclick="closeFullscreenNote(); editNote(${noteId});" class="fullscreen-action-btn edit-btn">
            <span>✏️</span>
            <span>Edit</span>
        </button>
    ` : '';

    const actionsHtml = `
        <button onclick="closeFullscreenNote(); togglePin(${noteId});" class="fullscreen-action-btn pin-btn">
            <span>📌</span>
            <span>${isPinned ? 'Unpin' : 'Pin'}</span>
        </button>
        ${editBtn}
        <button onclick="closeFullscreenNote(); duplicateNote(${noteId});" class="fullscreen-action-btn">
            <span>📋</span>
            <span>Duplicate</span>
        </button>
        <button onclick="closeFullscreenNote(); shareNote(${noteId});" class="fullscreen-action-btn share-btn">
            <span>📤</span>
            <span>Share</span>
        </button>
        <button onclick="closeFullscreenNote(); deleteNote(${noteId});" class="fullscreen-action-btn delete-btn">
            <span>🗑️</span>
            <span>Delete</span>
        </button>
    `;

    document.getElementById('fullscreenNoteActions').innerHTML = actionsHtml;

    // Show overlay
    document.getElementById('fullscreenNoteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeFullscreenNote() {
    document.getElementById('fullscreenNoteModal').classList.remove('active');
    document.body.style.overflow = '';
    currentFullscreenNoteId = null;
}

// Close fullscreen on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('fullscreenNoteModal').classList.contains('active')) {
        closeFullscreenNote();
    }
});

// Close fullscreen on backdrop click
document.getElementById('fullscreenNoteModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeFullscreenNote();
    }
});

// ============================================
// REAL-TIME DOM HELPERS
// ============================================

function addNoteToDOM(note) {
    // Remove empty state if present
    const emptyState = document.querySelector('.empty-state');
    if (emptyState) {
        emptyState.remove();
    }

    // Find or create notes grid
    let notesGrid = document.querySelector('.notes-section .notes-grid');
    if (!notesGrid) {
        const notesSection = document.querySelector('.notes-section');
        if (notesSection) {
            notesGrid = document.createElement('div');
            notesGrid.className = 'notes-grid';
            notesSection.appendChild(notesGrid);
        }
    }

    if (!notesGrid) return;

    // Create note card HTML
    const noteCard = document.createElement('div');
    noteCard.className = 'note-card';
    noteCard.dataset.noteId = note.id;
    noteCard.dataset.noteType = note.type;
    noteCard.style.background = note.color;
    noteCard.style.cursor = 'pointer';
    noteCard.style.animation = 'noteAppear 0.4s ease backwards';
    noteCard.onclick = () => openFullscreenNote(note.id);

    const titleHtml = note.title ? `<div class="note-title">${escapeHtml(note.title)}</div>` : '';

    let contentHtml = '';
    if (note.type === 'text') {
        contentHtml = `<div class="note-body">${escapeHtml(note.body).replace(/\n/g, '<br>')}</div>`;
    } else {
        contentHtml = `
            <div class="note-voice" onclick="event.stopPropagation();">
                <div class="voice-icon">🎤</div>
                <div class="voice-label">Voice Note</div>
                ${note.audio_path ? `
                    <audio controls onclick="event.stopPropagation();">
                        <source src="${escapeHtml(note.audio_path)}" type="audio/webm">
                        Your browser does not support audio playback.
                    </audio>
                ` : ''}
            </div>
        `;
    }

    // Add images if present (supports multiple images)
    let imageHtml = '';
    const imagePaths = parseImagePaths(note.image_path);
    if (imagePaths.length > 0) {
        const imageCount = imagePaths.length;
        const imagesJson = escapeHtml(JSON.stringify(imagePaths));
        imageHtml = `
            <div class="note-images-carousel" onclick="event.stopPropagation(); openImageCarousel(${imagesJson}, 0)" data-images='${imagesJson}'>
                <img src="${escapeHtml(imagePaths[0])}" alt="Note photo" loading="lazy">
                ${imageCount > 1 ? `<div class="carousel-indicator">${imageCount} photos</div>` : ''}
            </div>
        `;
    }

    const editBtn = note.type === 'text' ? `
        <button onclick="event.stopPropagation(); editNote(${note.id})" class="note-action" title="Edit">✏️</button>
    ` : '';

    noteCard.innerHTML = `
        <div class="note-header">
            <button onclick="event.stopPropagation(); togglePin(${note.id})" class="note-pin" title="Pin">📌</button>
            <div class="note-actions">
                ${editBtn}
                <button onclick="event.stopPropagation(); duplicateNote(${note.id})" class="note-action" title="Duplicate">📋</button>
                <button onclick="event.stopPropagation(); shareNote(${note.id})" class="note-action" title="Share">📤</button>
                <button onclick="event.stopPropagation(); deleteNote(${note.id})" class="note-action" title="Delete">🗑️</button>
            </div>
        </div>
        ${titleHtml}
        ${contentHtml}
        ${imageHtml}
        <div class="note-footer">
            <div class="note-author">
                <div class="author-avatar-mini" style="background: ${escapeHtml(note.avatar_color || '#667eea')}">
                    <img src="/saves/${note.user_id}/avatar/avatar.webp"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                         style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                    <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center;">${(note.user_name || 'U').substring(0, 1).toUpperCase()}</span>
                </div>
                <span>${escapeHtml(note.user_name || 'Unknown')}</span>
            </div>
            <div class="note-date">Just now</div>
        </div>
    `;

    // Add to beginning of grid
    notesGrid.insertBefore(noteCard, notesGrid.firstChild);
}

function showEmptyState() {
    const notesSection = document.querySelector('.notes-section');
    if (!notesSection) return;

    // Remove notes grid
    const notesGrid = notesSection.querySelector('.notes-grid');
    if (notesGrid) {
        notesGrid.remove();
    }

    // Add empty state
    const emptyState = document.createElement('div');
    emptyState.className = 'empty-state glass-card';
    emptyState.innerHTML = `
        <div class="empty-icon">📝</div>
        <h2>No notes yet</h2>
        <p>Start capturing your family's ideas and reminders</p>
        <div class="empty-actions">
            <button onclick="showCreateNoteModal('text')" class="btn btn-primary btn-lg">
                <span class="btn-icon">📝</span>
                <span>Create First Note</span>
            </button>
            <button onclick="showCreateNoteModal('voice')" class="btn btn-voice btn-lg">
                <span class="btn-icon">🎤</span>
                <span>Record Voice Note</span>
            </button>
        </div>
    `;
    emptyState.style.animation = 'noteAppear 0.4s ease backwards';

    notesSection.appendChild(emptyState);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================

function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast-notification');
    existingToasts.forEach(toast => toast.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    
    const icon = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    }[type] || 'ℹ️';
    
    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <span class="toast-message">${message}</span>
    `;
    
    document.body.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);
    
    // Auto remove
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add toast styles dynamically
if (!document.getElementById('toast-styles')) {
    const style = document.createElement('style');
    style.id = 'toast-styles';
    style.textContent = `
        .toast-notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            font-weight: 600;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            z-index: 10001;
            transform: translateX(400px);
            transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        
        .toast-notification.show {
            transform: translateX(0);
        }
        
        .toast-icon {
            font-size: 1.5rem;
            line-height: 1;
        }
        
        .toast-message {
            font-size: 0.95rem;
        }
        
        .toast-success {
            border-left: 4px solid #4caf50;
        }
        
        .toast-error {
            border-left: 4px solid #f44336;
        }
        
        .toast-warning {
            border-left: 4px solid #ff9800;
        }
        
        .toast-info {
            border-left: 4px solid #2196f3;
        }
        
        @media (max-width: 768px) {
            .toast-notification {
                right: 20px;
                left: 20px;
                bottom: 20px;
            }
        }
    `;
    document.head.appendChild(style);
}