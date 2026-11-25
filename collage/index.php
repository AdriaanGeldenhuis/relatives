<?php
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collage Builder</title>
    <link rel="stylesheet" href="/shared/css/family-surfaces.css?v=<?= filemtime(__DIR__ . '/../shared/css/family-surfaces.css') ?>">
    <link rel="stylesheet" href="/shared/css/action-controls.css?v=<?= filemtime(__DIR__ . '/../shared/css/action-controls.css') ?>">
    <link rel="stylesheet" href="css/collage.css?v=<?= filemtime(__DIR__ . '/css/collage.css') ?>">
</head>
<body class="collage-page" data-area="collage">
<header class="collage-heading">
    <a href="/notes/">Back to notes</a>
    <h1>Collage builder</h1>
</header>

<div class="modal">
    <div class="modal-body">

        <div class="collage-toolbar">
            <button type="button" class="btn" id="btnChooseImages">Choose images</button>
            <button type="button" class="btn" id="btnChooseLayout">Choose layout</button>
            <button type="button" class="btn" id="btnChooseBackground">Choose background</button>
            <button type="button" class="btn btn-primary" id="btnDone">Done</button>
            <button type="button" class="btn btn-danger" id="btnDelete">Delete</button>
        </div>

        <div id="collageCanvasWrapper">
            <div id="collageCanvas"></div>
        </div>

    </div>
</div>

<!-- Modular JS in correct load order -->
<script src="js/collage.state.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.images.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.drag.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.resize.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.rotate.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.layouts.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.background.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.cleanup.js?v=<?php echo $cacheVersion; ?>"></script>
<script src="js/collage.init.js?v=<?php echo $cacheVersion; ?>"></script>

</body>
</html>
