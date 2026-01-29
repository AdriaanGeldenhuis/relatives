<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Collage Builder</title>
    <link rel="stylesheet" href="css/collage.css">
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
<script src="js/collage.state.js"></script>
<script src="js/collage.images.js"></script>
<script src="js/collage.drag.js"></script>
<script src="js/collage.resize.js"></script>
<script src="js/collage.rotate.js"></script>
<script src="js/collage.layouts.js"></script>
<script src="js/collage.background.js"></script>
<script src="js/collage.cleanup.js"></script>
<script src="js/collage.init.js"></script>

</body>
</html>
