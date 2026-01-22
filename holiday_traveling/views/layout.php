<?php
/**
 * Holiday Traveling - Layout Template
 * All views extend this layout
 *
 * Expected variables:
 * - $pageTitle: Page title
 * - $pageContent: Main content (HTML string or use output buffering)
 * - $pageCSS: Array of additional CSS files
 * - $pageJS: Array of additional JS files
 */

// Default values
$pageTitle = $pageTitle ?? 'Holiday & Traveling';
$pageCSS = $pageCSS ?? [];
$pageJS = $pageJS ?? [];

// Always include module CSS
array_unshift($pageCSS, '/holiday_traveling/assets/css/holiday.css');

// Get user data for header
$user = HT_Auth::user();
?>
<?php require __DIR__ . '/../../../shared/components/header.php'; ?>

<main class="ht-main">
    <div class="ht-container">
        <?php if (isset($pageContent)): ?>
            <?php echo $pageContent; ?>
        <?php else: ?>
            <?php
            // If no $pageContent, use output buffer contents
            // This allows views to use this layout with ob_start()
            ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../../../shared/components/footer.php'; ?>

<!-- Module JavaScript -->
<script src="/holiday_traveling/assets/js/holiday.js?v=<?php echo $buildTime ?? '1.0.0'; ?>"></script>

<?php if (!empty($pageJS)): ?>
    <?php foreach ($pageJS as $js): ?>
        <script src="<?php echo $js; ?>?v=<?php echo $buildTime ?? '1.0.0'; ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Pass CSRF token and user data to JS -->
<script>
    window.HT = window.HT || {};
    window.HT.csrfToken = '<?php echo HT_CSRF::token(); ?>';
    window.HT.userId = <?php echo HT_Auth::userId() ?? 'null'; ?>;
    window.HT.familyId = <?php echo HT_Auth::familyId() ?? 'null'; ?>;
</script>
