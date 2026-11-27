<?php
declare(strict_types=1);

const FAMILY_SCENES = ['travel', 'planner', 'notes', 'shopping', 'family'];

function familyScenePath(string $scene): string
{
    return __DIR__ . "/../public/family-$scene.webp";
}

function renderFamilyScene(string $scene): void
{
    if (!in_array($scene, FAMILY_SCENES, true)) {
        return;
    }
    $version = filemtime(familyScenePath($scene));
    $url = '/weather/api/welcome-image.php?' . http_build_query(['family' => $scene, 'v' => $version]);
    ?>
    <span class="family-scene" data-scene="<?= $scene ?>" aria-hidden="true"
          style="<?= htmlspecialchars("--scene-image: url('$url')") ?>"></span>
    <?php
}
