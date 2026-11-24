<?php
declare(strict_types=1);

// Collage still uses this route to upload its photos.
require_once __DIR__ . '/../../core/session_boot.php';
require_once __DIR__ . '/../store.php';

header('Cache-Control: private, no-store');
if (empty($_SESSION['user_id'])) {
    notesRespond(['success' => false, 'error' => 'Sign in again to upload photos.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    notesRespond(['success' => false, 'error' => 'Use POST to upload a photo.'], 405);
}

require_once __DIR__ . '/../../core/bootstrap.php';
$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    unset($_SESSION['user_id']);
    notesRespond(['success' => false, 'error' => 'Sign in again to upload photos.'], 401);
}
session_write_close();

$file = $_FILES['photo'] ?? null;
if (!is_array($file)) {
    notesRespond(['success' => false, 'error' => 'Choose a photo within the upload limit.'], 400);
}

$created = [];
try {
    $path = notesSavePhoto($file, (int)$user['id'], $created, forCollage: true);
    notesRespond(['success' => true, 'url' => $path, 'filename' => basename($path)]);
} catch (Throwable $error) {
    notesRemoveMedia($created);
    if ($error instanceof DomainException) {
        notesRespond(['success' => false, 'error' => $error->getMessage()], 400);
    }
    error_log('Collage photo upload failed: ' . $error->getMessage());
    notesRespond(['success' => false, 'error' => 'Could not save the photo. Try another image.'], 500);
}
