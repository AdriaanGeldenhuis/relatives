<?php
declare(strict_types=1);

/**
 * ============================================
 * PROFILE PICTURE PAGE v1.0
 * Change profile picture and avatar color
 * ============================================
 */

require_once __DIR__ . '/../core/session_boot.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CSRF.php';
require_once __DIR__ . '/../shared/components/avatar.php';

try {
    $auth = new Auth($db);
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login.php?session_expired=1', true, 302);
        exit;
    }
} catch (Exception $e) {
    error_log('Picture page error: ' . $e->getMessage());
    header('Location: /login.php?error=1', true, 302);
    exit;
}

$error = '';
$success = '';
$csrfToken = CSRF::generate();

// Avatar color options
$avatarColors = [
    '#667eea' => 'Periwinkle', '#764ba2' => 'Purple', '#f093fb' => 'Orchid', '#4facfe' => 'Blue',
    '#43e97b' => 'Green', '#fa709a' => 'Rose', '#fee140' => 'Yellow', '#30cfd0' => 'Turquoise',
    '#a8edea' => 'Mint', '#fed6e3' => 'Pink', '#fccb90' => 'Peach', '#d57eeb' => 'Lilac',
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !CSRF::validate($token)) {
            http_response_code(403);
            throw new DomainException('Reload Profile Picture and try again.');
        }
        $newColor = $_POST['avatar_color'] ?? null;
        if (is_string($newColor) && isset($avatarColors[$newColor])) {
            $stmt = $db->prepare('UPDATE users SET avatar_color = ? WHERE id = ?');
            $stmt->execute([$newColor, $user['id']]);
            $user['avatar_color'] = $newColor;
            $success = 'Avatar color updated.';
        } elseif (isset($_FILES['profile_picture'])) {
            $file = $_FILES['profile_picture'];
            $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new DomainException('Choose a photo within the 5 MB upload limit.');
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new DomainException('Choose a photo and try again.');
            }
            $dimensions = @getimagesize($file['tmp_name']);
            if (!$dimensions || !in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
                || $dimensions[0] * $dimensions[1] > 24000000) {
                throw new DomainException('Choose a JPG, PNG, GIF or WebP of up to 24 megapixels.');
            }
            $image = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
            if (!$image) throw new DomainException('Could not read this photo. Choose another image.');

            $path = avatarPath($user['id']);
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create the avatar directory.');
            }
            $temporary = tempnam($directory, '.avatar-');
            try {
                if (!$temporary || dirname($temporary) !== realpath($directory)) {
                    throw new RuntimeException('Could not prepare the avatar replacement.');
                }
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                if (!imagewebp($image, $temporary, 85) || !filesize($temporary) || !chmod($temporary, 0644) || !rename($temporary, $path)) {
                    throw new RuntimeException('Could not save the avatar.');
                }
                clearstatcache(true, $path);
                $success = 'Profile picture updated.';
            } finally {
                if ($temporary && is_file($temporary)) unlink($temporary);
            }
        }
    } catch (DomainException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Picture update error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Profile Picture';
$pageCSS = ['/profile/css/profile.css?build=' . filemtime(__DIR__ . '/css/profile.css')];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <div class="profile-header">
            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <?php renderProfileAvatar($user); ?>
            </div>
            <h1 class="profile-name">Profile Picture</h1>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Upload Picture -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Upload Photo</span>
            </div>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <label class="picture-upload-area" id="dropZone" for="pictureInput">
                    <span class="upload-text">Choose or drop a photo</span>
                    <span class="upload-hint" id="pictureHint">JPG, PNG, GIF or WebP up to 5 MB</span>
                    <input type="file" id="pictureInput" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp" aria-describedby="pictureHint" required>
                </label>
                <div class="picture-preview" id="previewContainer" hidden>
                    <img alt="New profile picture preview" class="preview-image" id="previewImage">
                </div>
                <button type="submit" class="btn btn-primary btn-block picture-submit">Upload Picture</button>
            </form>

        </div>

        <!-- Avatar Color -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Avatar Color</span>
            </div>

            <p style="font-size: 13px; color: var(--family-muted); margin-bottom: 15px;">
                Choose a background color for your avatar when no picture is set.
            </p>

            <form method="POST" id="colorForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="color-picker" role="group" aria-label="Avatar colors">
                    <?php foreach ($avatarColors as $color => $label): ?>
                        <?php $selected = ($user['avatar_color'] ?? '#667eea') === $color; ?>
                        <button type="submit" class="color-option<?= $selected ? ' selected' : '' ?>" name="avatar_color" value="<?= $color ?>"
                                aria-label="<?= $label ?>" aria-pressed="<?= $selected ? 'true' : 'false' ?>" style="background: <?= $color ?>"></button>
                    <?php endforeach; ?>
                </div>
            </form>
        </div>

        <a href="/profile/" class="btn btn-secondary btn-block" style="margin-top: 20px;">
            Back to Profile
        </a>

    </div>
</main>

<script>
// File upload preview
const pictureInput = document.getElementById('pictureInput');
const previewContainer = document.getElementById('previewContainer');
const previewImage = document.getElementById('previewImage');
const dropZone = document.getElementById('dropZone');
let previewUrl;

pictureInput.addEventListener('change', () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    const file = pictureInput.files[0];
    previewContainer.hidden = true;
    if (!file) return;
    previewUrl = URL.createObjectURL(file);
    previewImage.src = previewUrl;
});
previewImage.addEventListener('load', () => { previewContainer.hidden = false; });

// Drag and drop
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

    const files = e.dataTransfer.files;
    if (files.length > 0) {
        pictureInput.files = files;
        const event = new Event('change');
        pictureInput.dispatchEvent(event);
    }
});

</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
