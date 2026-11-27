<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/components/family-scenes.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collage Builder</title>
    <link rel="stylesheet" href="css/collage.css?v=<?= filemtime(__DIR__ . '/css/collage.css') ?>">
    <style><?php
        readfile(__DIR__ . '/../shared/css/family-surfaces.css');
        readfile(__DIR__ . '/../shared/css/action-controls.css');
        readfile(__DIR__ . '/../shared/css/family-theme.css');
    ?></style>
</head>
<body class="collage-page" data-area="collage" data-family-theme>
<header class="collage-heading photo-hero photo-hero-compact">
    <a href="/notes/">Back to notes</a>
    <h1>Collage builder</h1>
    <?php renderFamilyScene('notes'); ?>
</header>

<main class="collage-workspace" aria-label="Collage editor">
    <div class="modal-body">

        <div class="collage-toolbar">
            <button type="button" class="btn" id="btnChooseImages">Choose photos</button>
            <button type="button" class="btn" id="btnChooseLayout" aria-expanded="false">Choose layout</button>
            <label class="btn collage-background" for="collageColor">Background <input type="color" id="collageColor" value="#e8f4f8"></label>
            <button type="button" class="btn btn-primary" id="btnDone" disabled>Download image</button>
            <button type="button" class="btn btn-danger" id="btnDelete" disabled>Clear collage</button>
        </div>

        <div id="collageCanvasWrapper">
            <div id="collageCanvas">
                <p id="collageEmpty">Choose photos or drop them here.</p>
            </div>
        </div>
        <p id="collageStatus" class="collage-status" role="status"></p>

    </div>
</main>

<!-- Modular JS in correct load order -->
<?php foreach (['state', 'images', 'drag', 'resize', 'rotate', 'layouts', 'background', 'cleanup', 'init'] as $module): ?>
    <script defer src="js/collage.<?= $module ?>.js?v=<?= filemtime(__DIR__ . '/js/collage.' . $module . '.js') ?>"></script>
<?php endforeach; ?>

</body>
</html>
