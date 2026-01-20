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

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    initParticles();
    initNoteAnimations();
    updateStats();
});

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

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('active');
    
    // Reset forms
    if (modalId === 'createTextNoteModal') {
        document.getElementById('noteTitle').value = '';
        document.getElementById('noteBody').value = '';
        document.querySelector('input[name="noteColor"]:checked').checked = false;
        document.getElementById('color1').checked = true;
    } else if (modalId === 'createVoiceNoteModal') {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
        resetVoiceRecorder();
    } else if (modalId === 'editNoteModal') {
        document.getElementById('editNoteId').value = '';
        document.getElementById('editNoteTitle').value = '';
        document.getElementById('editNoteBody').value = '';
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
        
        if (!body) {
            showToast('Please enter note content', 'error');
            return;
        }
        
        formData.append('title', title);
        formData.append('body', body);
        formData.append('color', color);
        
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
            
            // Reload page to show new note
            setTimeout(() => {
                location.reload();
            }, 500);
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
                    
                    // Check if no notes left
                    const allCards = document.querySelectorAll('.note-card');
                    if (allCards.length === 0) {
                        location.reload();
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
            // Reload to re-organize sections
            location.reload();
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

async function startRecording() {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        
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
        
        // Update UI
        document.getElementById('startRecordBtn').style.display = 'none';
        document.getElementById('stopRecordBtn').style.display = 'inline-flex';
        document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '⏺️';
        document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Recording...';
        
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
            errorMessage = 'Microphone permission denied. Please allow microphone access in your browser settings.';
        } else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') {
            errorMessage = 'Microphone is in use by another application. Please close other apps using the microphone.';
        } else if (error.name === 'OverconstrainedError') {
            errorMessage = 'No suitable microphone found for the requested settings.';
        } else if (error.name === 'TypeError') {
            errorMessage = 'Your browser does not support audio recording.';
        }

        showToast(errorMessage, 'error');
    }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        
        // Stop timer
        clearInterval(recordingTimer);
        
        // Update UI
        document.getElementById('stopRecordBtn').style.display = 'none';
        document.getElementById('recordingStatus').querySelector('.recording-icon').textContent = '✅';
        document.getElementById('recordingStatus').querySelector('.recording-text').textContent = 'Recording complete';
    }
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