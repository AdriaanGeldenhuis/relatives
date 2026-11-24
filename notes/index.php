<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';
require_once __DIR__ . '/store.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$ajax = ($_GET['ajax'] ?? null) === '1';
header('Cache-Control: private, no-store');

$user = null;
if (!empty($_SESSION['user_id'])) {
    require_once __DIR__ . '/../core/bootstrap.php';
    $user = (new Auth($db))->getCurrentUser();
}

if (!$user) {
    unset($_SESSION['user_id']);
    if ($isPost || $ajax) {
        notesRespond(['success' => false, 'error' => 'Sign in again to use Notes.'], 401);
    }
    header('Location: /login.php?redirect=%2Fnotes%2F');
    exit;
}

require_once __DIR__ . '/../core/CSRF.php';
$csrfToken = CSRF::generate();
session_write_close();
if ($isPost) {
    notesHandlePost($db, $user, $csrfToken);
}

$familyId = (int)$user['family_id'];
$notes = notesBoardData($db, $familyId);
if ($ajax) {
    notesRespond(['success' => true, 'notes' => $notes]);
}
$startNew = ($_GET['new'] ?? null) === '1';
$prefill = $startNew && is_string($_GET['content'] ?? null) ? $_GET['content'] : '';
$event = null;
$linkNotice = '';
if ($startNew && isset($_GET['event'])) {
    $id = eventNoteId($_GET['event']);
    $event = $id ? eventNotePreview($db, $familyId, $id) : null;
    if (!$event) {
        $linkNotice = 'This calendar event is no longer available.';
    }
}
$openNote = eventNoteId($_GET['note'] ?? null);
$openNote = $openNote ? (string)$openNote : null;
$pageTitle = 'Notes';
$pageInlineStylesheets = [__DIR__ . '/css/notes.css'];
$pageJS = ['/notes/js/notes.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/notes.js');
$imageCapacity = notesImageCapacity($db);

function notesIcon(string $name): string
{
    return '<svg class="notes-icon" aria-hidden="true"><use href="#notes-icon-' . $name . '"></use></svg>';
}

require_once __DIR__ . '/../shared/components/header.php';
?>

<svg width="0" height="0" class="notes-symbols" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <defs>
        <symbol id="notes-icon-note" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-8 4h6"/></symbol>
        <symbol id="notes-icon-list" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m7 9 1.5 1.5L11 8m2 2h4m-10 5h2m4 0h4"/></symbol>
        <symbol id="notes-icon-mic" viewBox="0 0 24 24"><rect x="9" y="2" width="6" height="13" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2m-7 9v3m-4 0h8"/></symbol>
        <symbol id="notes-icon-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
        <symbol id="notes-icon-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></symbol>
        <symbol id="notes-icon-pin" viewBox="0 0 24 24"><path d="M15 3 21 9l-3 1-3 3v4l-2 2-8-8 2-2h4l3-3 1-3zM9 15l-6 6"/></symbol>
        <symbol id="notes-icon-pencil" viewBox="0 0 24 24"><path d="m13 6 5 5M4 20l5-1 11-11a2.8 2.8 0 0 0-4-4L5 15l-1 5z"/></symbol>
        <symbol id="notes-icon-arrow" viewBox="0 0 24 24"><path d="M7 17 17 7M8 7h9v9"/></symbol>
        <symbol id="notes-icon-photo" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="1.5"/><path d="m21 16-5-5L5 21"/></symbol>
        <symbol id="notes-icon-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
        <symbol id="notes-icon-rows" viewBox="0 0 24 24"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></symbol>
        <symbol id="notes-icon-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
        <symbol id="notes-icon-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13m-7 4v5m3-5v5"/></symbol>
        <symbol id="notes-icon-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 4H6a2 2 0 0 0-2 2v10"/></symbol>
        <symbol id="notes-icon-chevron" viewBox="0 0 24 24"><path d="m14 6-6 6 6 6"/></symbol>
        <symbol id="notes-icon-check" viewBox="0 0 24 24"><path d="m5 12 5 5L19 7"/></symbol>
    </defs>
</svg>

<main class="notes-main family-main" id="notesApp">
    <header class="family-hero app-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Family notes</h1>
            <p class="family-hero-summary">Keep lists, photos and little reminders in one place.</p>
            <div class="family-actions">
                <button type="button" class="family-button family-button-primary" data-create="text"><?= notesIcon('plus') ?> New note</button>
                <button type="button" class="family-button" data-create="list"><?= notesIcon('list') ?> Checklist</button>
                <button type="button" class="family-button" data-create="voice"><?= notesIcon('mic') ?> Record</button>
            </div>
        </div>
        <?php renderFamilyAppArt('notes', true); ?>
    </header>

    <div class="notes-toolbar">
        <div class="notes-filters" role="group" aria-label="Filter notes">
            <button type="button" data-filter="all" aria-pressed="true">All notes <span id="notesTotal"><?= count($notes) ?></span></button>
            <button type="button" data-filter="pinned" aria-pressed="false"><?= notesIcon('pin') ?> Pinned</button>
            <button type="button" data-filter="list" aria-pressed="false"><?= notesIcon('list') ?> Checklists</button>
            <button type="button" data-filter="text" aria-pressed="false"><?= notesIcon('note') ?> Text</button>
            <button type="button" data-filter="photos" aria-pressed="false"><?= notesIcon('photo') ?> Photos</button>
            <button type="button" data-filter="voice" aria-pressed="false"><?= notesIcon('mic') ?> Voice</button>
        </div>
        <label class="notes-search"><?= notesIcon('search') ?><span class="notes-sr-only">Search notes</span>
            <input id="notesSearch" type="search" placeholder="Search notes…" autocomplete="off">
        </label>
    </div>

    <div class="notes-board-tools">
        <h2 class="family-section-title" id="notesBoardHeading">Board</h2>
        <div class="notes-view-tools">
            <label class="notes-sort"><span class="notes-sr-only">Sort notes</span>
                <select id="notesSort">
                    <option value="new">Newest first</option>
                    <option value="updated">Recently edited</option>
                    <option value="old">Oldest first</option>
                    <option value="title">Title A–Z</option>
                </select>
            </label>
            <div class="notes-layout" role="group" aria-label="Board layout">
                <button type="button" data-layout="grid" aria-label="Grid layout" aria-pressed="true" title="Grid layout"><?= notesIcon('grid') ?></button>
                <button type="button" data-layout="list" aria-label="List layout" aria-pressed="false" title="List layout"><?= notesIcon('rows') ?></button>
            </div>
        </div>
    </div>

    <div id="notesBoard" class="notes-board" aria-labelledby="notesBoardHeading"></div>
    <p id="notesResultCount" class="notes-sr-only" role="status"></p>
    <noscript><p class="notes-empty">Enable JavaScript to use the family notes board.</p></noscript>
    <div id="notesToast" class="notes-toast" role="status" hidden></div>
</main>

<dialog class="notes-dialog" id="notesEditor" aria-labelledby="notesEditorHeading">
    <form id="notesForm">
        <header class="notes-dialog-header">
            <h2 id="notesEditorHeading">New note</h2>
            <button type="button" class="notes-icon-button" data-close aria-label="Close editor"><?= notesIcon('close') ?></button>
        </header>
        <div class="notes-dialog-body">
            <p id="notesSourceEvent" class="notes-hint" hidden>Linked calendar event <a target="_blank" rel="noopener"></a></p>
            <div id="notesRecorder" class="notes-recorder" hidden>
                <div class="notes-recorder-icon"><?= notesIcon('mic') ?></div>
                <output id="notesTimer">00:00</output>
                <div id="notesRecordStatus" role="status">Ready to record</div>
                <button type="button" id="notesStartRecord" class="notes-button notes-button-primary">Start recording</button>
                <button type="button" id="notesStopRecord" class="notes-button notes-button-danger" hidden>Stop recording</button>
                <audio id="notesRecordingPreview" controls hidden></audio>
            </div>
            <label class="notes-field">Title <input name="title" id="notesTitle" maxlength="160" placeholder="Give it a title"></label>
            <div class="notes-field" id="notesBodyField">
                <div class="notes-field-head">
                    <label for="notesBody">Note</label>
                    <button type="button" class="notes-button notes-button-small" id="notesInsertTasks" title="Convert selected lines or existing bullets to checkboxes"><?= notesIcon('list') ?> Add checkboxes</button>
                </div>
                <textarea name="body" id="notesBody" rows="8" placeholder="Write something to remember…" spellcheck="true"></textarea>
                <p id="notesChecklistHint" class="notes-hint" hidden>Press Enter to add another item. Tick items from the board or the open note.</p>
            </div>
            <?php if ($imageCapacity): ?>
            <div id="notesAttachments" class="notes-field">
                <div class="notes-field-head"><span>Photos</span><span class="notes-hint" id="notesPhotoCount"></span></div>
                <div id="notesPhotoPreviews" class="notes-photo-previews"></div>
                <button type="button" id="notesAddPhotos" class="notes-upload"><?= notesIcon('photo') ?> Add or drop photos</button>
                <input type="file" id="notesPhotoInput" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
            </div>
            <?php endif; ?>
            <fieldset class="notes-colors">
                <legend>Note color</legend>
                <?php foreach ([
                    'Paper' => ['paper', '#ffffff'], 'Honey' => ['honey', '#ffeb3b'],
                    'Peach' => ['peach', '#ff9800'], 'Rose' => ['rose', '#e91e63'],
                    'Lilac' => ['lilac', '#9c27b0'], 'Sky' => ['sky', '#2196f3'],
                    'Aqua' => ['aqua', '#00bcd4'], 'Mint' => ['mint', '#4caf50'],
                    'Lime' => ['lime', '#8bc34a']
                ] as $name => [$tone, $color]): ?>
                    <label class="notes-color" data-tone="<?= $tone ?>" title="<?= $name ?>">
                        <input type="radio" name="color" value="<?= $color ?>" aria-label="<?= $name ?>">
                        <span></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p id="notesFormError" class="notes-form-error" role="alert" hidden></p>
        </div>
        <footer class="notes-dialog-footer">
            <button type="button" class="notes-button" data-close>Cancel</button>
            <button type="submit" class="notes-button notes-button-primary" id="notesSave">Save note</button>
        </footer>
    </form>
</dialog>

<dialog class="notes-dialog" id="notesReader" aria-labelledby="notesReaderHeading">
    <div id="notesReaderSurface">
        <header class="notes-dialog-header">
            <div><div id="notesReaderKind" class="notes-card-kind"></div><h2 id="notesReaderHeading" tabindex="-1"></h2></div>
            <button type="button" class="notes-icon-button" data-close aria-label="Close note"><?= notesIcon('close') ?></button>
        </header>
        <div class="notes-dialog-body" id="notesReaderContent"></div>
        <p class="notes-reader-feedback" id="notesReaderFeedback" role="status" hidden></p>
        <footer class="notes-dialog-footer notes-reader-actions" id="notesReaderActions"></footer>
    </div>
</dialog>

<dialog class="notes-gallery" id="notesGallery" aria-label="Note photos">
    <div class="notes-gallery-header">
        <p id="notesGalleryCount" role="status"></p>
        <button type="button" class="notes-icon-button" data-close aria-label="Close photos"><?= notesIcon('close') ?></button>
    </div>
    <div class="notes-gallery-stage">
        <button type="button" class="notes-icon-button" id="notesPrevPhoto" aria-label="Previous photo"><?= notesIcon('chevron') ?></button>
        <div class="notes-gallery-photo"><img id="notesGalleryImage" alt=""><p id="notesGalleryError" hidden>This photo is unavailable.</p></div>
        <button type="button" class="notes-icon-button notes-gallery-next" id="notesNextPhoto" aria-label="Next photo"><?= notesIcon('chevron') ?></button>
    </div>
</dialog>

<dialog class="notes-dialog notes-delete-dialog" id="notesDelete" aria-labelledby="notesDeleteHeading">
    <header class="notes-dialog-header"><h2 id="notesDeleteHeading">Delete this note?</h2></header>
    <div class="notes-dialog-body"><p>This removes the note and its attachments from the family board.</p><p id="notesDeleteError" class="notes-form-error" role="alert" hidden></p></div>
    <footer class="notes-dialog-footer">
        <button type="button" class="notes-button" data-close autofocus>Cancel</button>
        <button type="button" class="notes-button notes-button-danger" id="notesConfirmDelete">Delete note</button>
    </footer>
</dialog>

<script type="application/json" id="notesData"><?= json_encode(
    ['notes' => $notes, 'csrf' => $csrfToken, 'prefill' => $prefill, 'startNew' => $startNew,
        'maxPhotos' => NOTES_MAX_PHOTOS, 'uploadLimits' => notesUploadLimits(),
        'event' => $event ? ['id' => (string)$event['id'], 'title' => $event['title']] : null,
        'openNote' => $openNote, 'linkNotice' => $linkNotice],
    JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
) ?></script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
