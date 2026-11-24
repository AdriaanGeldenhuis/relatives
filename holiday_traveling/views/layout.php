<?php
/**
 * Shared layout for Holiday & Traveling views.
 */
$pageTitle = $pageTitle ?? 'Holiday & Traveling';
$holidayStylesheet = '/holiday_traveling/assets/css/holiday.css';
$pageCSS = array_merge(
    [$holidayStylesheet . '?build=' . filemtime(__DIR__ . '/../../' . ltrim($holidayStylesheet, '/'))],
    $pageCSS ?? []
);
$pageJS = array_merge(['/holiday_traveling/assets/js/holiday.js'], $pageJS ?? []);
$pageJSVersion = max(array_map(static function (string $path): int {
    return filemtime(__DIR__ . '/../../' . ltrim($path, '/'));
}, $pageJS));
$user = HT_Auth::user();
$holidaySettings = [
    'csrfToken' => HT_CSRF::token(),
    'userId' => HT_Auth::userId(),
    'familyId' => HT_Auth::familyId()
];

require __DIR__ . '/../../shared/components/header.php';
?>

<main class="family-main ht-main">
    <div class="ht-container">
        <?= $pageContent ?? '' ?>
    </div>
</main>

<script>
    window.HT = Object.assign(window.HT || {}, <?= json_encode($holidaySettings, JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?>);
    window.HT.getCSRFToken = function() { return window.HT.csrfToken || ''; };
</script>

<?php require __DIR__ . '/../../shared/components/footer.php'; ?>
