<?php
$cacheVersion = '1.0.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collage Builder</title>
    <link rel="stylesheet" href="css/collage.css?v=<?php echo $cacheVersion; ?>">
</head>
<body>

<div class="modal">
    <div class="modal-body">

        <div class="collage-toolbar">
            <button id="btnChooseImages">Choose images</button>
            <button id="btnChooseLayout">Choose layout</button>
            <button id="btnChooseBackground">Choose background</button>
            <button id="btnDone">Done</button>
            <button id="btnDelete">Delete</button>
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
