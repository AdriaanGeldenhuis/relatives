<?php
declare(strict_types=1);

/**
 * ============================================
 * PROFILE PICTURE PAGE v1.0
 * Change profile picture and avatar color
 * ============================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: /login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

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

// Avatar color options
$avatarColors = [
    '#667eea', '#764ba2', '#f093fb', '#4facfe',
    '#43e97b', '#fa709a', '#fee140', '#30cfd0',
    '#a8edea', '#fed6e3', '#fccb90', '#d57eeb'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle avatar color change
        if (isset($_POST['avatar_color']) && !empty($_POST['avatar_color'])) {
            $newColor = $_POST['avatar_color'];

            // Validate color is in allowed list
            if (in_array($newColor, $avatarColors)) {
                $stmt = $db->prepare("UPDATE users SET avatar_color = ? WHERE id = ?");
                $stmt->execute([$newColor, $user['id']]);
                $user['avatar_color'] = $newColor;
                $success = 'Avatar color updated successfully!';
            }
        }

        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            // Validate file type
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            if (!in_array($mimeType, $allowedTypes)) {
                $error = 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.';
            } elseif ($file['size'] > $maxSize) {
                $error = 'File is too large. Maximum size is 5MB.';
            } else {
                // Create user's avatar directory
                $uploadDir = __DIR__ . '/../saves/' . $user['id'] . '/avatar/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $filepath = $uploadDir . 'avatar.webp';

                // Load image based on type
                $sourceImage = null;
                switch ($mimeType) {
                    case 'image/jpeg':
                        $sourceImage = imagecreatefromjpeg($file['tmp_name']);
                        break;
                    case 'image/png':
                        $sourceImage = imagecreatefrompng($file['tmp_name']);
                        break;
                    case 'image/gif':
                        $sourceImage = imagecreatefromgif($file['tmp_name']);
                        break;
                    case 'image/webp':
                        $sourceImage = imagecreatefromwebp($file['tmp_name']);
                        break;
                }

                if ($sourceImage) {
                    // Preserve transparency for PNG
                    imagepalettetotruecolor($sourceImage);
                    imagealphablending($sourceImage, true);
                    imagesavealpha($sourceImage, true);

                    // Save as webp with good quality
                    if (imagewebp($sourceImage, $filepath, 85)) {
                        imagedestroy($sourceImage);
                        $success = 'Profile picture uploaded successfully!';
                    } else {
                        imagedestroy($sourceImage);
                        $error = 'Failed to convert image. Please try again.';
                    }
                } else {
                    $error = 'Failed to process the image. Please try again.';
                }
            }
        }

        // Handle picture removal
        if (isset($_POST['remove_picture'])) {
            $avatarFile = __DIR__ . '/../saves/' . $user['id'] . '/avatar/avatar.webp';
            if (file_exists($avatarFile)) {
                unlink($avatarFile);
            }
            $success = 'Profile picture removed.';
        }

    } catch (Exception $e) {
        error_log('Picture update error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Profile Picture';
$pageCSS = ['/profile/css/profile.css'];

require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="main-content">
    <div class="profile-container">

        <?php $avatarPath = '/saves/' . $user['id'] . '/avatar/avatar.webp'; ?>
        <div class="profile-header">
            <div class="profile-avatar-large" style="background: <?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <img src="<?php echo htmlspecialchars($avatarPath); ?>?t=<?php echo time(); ?>"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                     alt="Profile Picture" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:48px; font-weight:800;">
                    <?php echo strtoupper(substr($user['name'] ?? $user['full_name'] ?? '?', 0, 1)); ?>
                </span>
            </div>
            <div class="profile-name"><?php echo htmlspecialchars($user['name'] ?? $user['full_name'] ?? 'User'); ?></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Upload Picture -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Upload Photo</span>
            </div>

            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <div class="picture-upload-area" id="dropZone" onclick="document.getElementById('pictureInput').click()">
                    <div class="upload-icon">+</div>
                    <div class="upload-text">Click or drag to upload</div>
                    <div class="upload-hint">JPG, PNG, GIF or WebP. Max 5MB.</div>
                </div>

                <input type="file" id="pictureInput" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">

                <div class="picture-preview" id="previewContainer">
                    <img src="" alt="Preview" class="preview-image" id="previewImage">
                    <button type="submit" class="btn btn-primary btn-block">Upload Picture</button>
                </div>
            </form>

            <?php if (file_exists(__DIR__ . '/../saves/' . $user['id'] . '/avatar/avatar.webp')): ?>
                <form method="POST" style="margin-top: 15px;">
                    <button type="submit" name="remove_picture" value="1" class="btn btn-danger btn-block" onclick="return confirm('Remove your profile picture?')">
                        Remove Current Picture
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Avatar Color -->
        <div class="settings-section">
            <div class="settings-section-title">
                <span class="icon">Avatar Color</span>
            </div>

            <p style="font-size: 13px; color: rgba(255, 255, 255, 0.7); margin-bottom: 15px;">
                Choose a background color for your avatar when no picture is set.
            </p>

            <form method="POST" id="colorForm">
                <div class="color-picker">
                    <?php foreach ($avatarColors as $color): ?>
                        <div class="color-option <?php echo ($user['avatar_color'] ?? '#667eea') === $color ? 'selected' : ''; ?>"
                             style="background: <?php echo $color; ?>"
                             data-color="<?php echo $color; ?>"
                             onclick="selectColor(this, '<?php echo $color; ?>')">
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="avatar_color" id="selectedColor" value="<?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>">
                <button type="submit" class="btn btn-primary btn-block" style="margin-top: 20px;" id="saveColorBtn" disabled>
                    Save Color
                </button>
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

pictureInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewContainer.classList.add('active');
            dropZone.style.display = 'none';
        };
        reader.readAsDataURL(this.files[0]);
    }
});

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

// Color selection
const originalColor = '<?php echo htmlspecialchars($user['avatar_color'] ?? '#667eea'); ?>';
const saveColorBtn = document.getElementById('saveColorBtn');

function selectColor(element, color) {
    document.querySelectorAll('.color-option').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');
    document.getElementById('selectedColor').value = color;

    // Update preview avatar
    document.querySelector('.profile-avatar-large').style.background = color;

    // Enable save button if color changed
    saveColorBtn.disabled = (color === originalColor);
}
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
