<?php
declare(strict_types=1);

require_once __DIR__ . '/core/session_boot.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /home/', true, 302);
    exit;
}

$pageTitle = 'Relatives — A little closer, every day';
require __DIR__ . '/shared/components/public-head.php';
?>
<body>
    <?php require __DIR__ . '/shared/components/site-navigation.php'; ?>
    <div class="public-wrap">
        <main>
            <section class="landing-hero" aria-labelledby="landing-title">
                <div class="landing-copy">
                    <h1 id="landing-title">A little closer.<br><em>Every day.</em></h1>
                    <p>Make plans, share lists, and stay in touch. Relatives gives your family one place for it all.</p>
                    <div class="landing-actions">
                        <a class="button button-lime" href="/register-create.php">Create your family hub <span class="button-arrow" aria-hidden="true"></span></a>
                        <a class="text-link" href="/register-join.php">I have an invite</a>
                    </div>
                </div>
                <div class="landing-photo">
                    <img src="/shared/public/family-table.webp" width="1320" height="880" fetchpriority="high" alt="Family laughing over a meal in the garden">
                </div>
            </section>

            <section class="landing-features" aria-label="What you can do with Relatives">
                <article class="feature">
                    <span class="feature-icon feature-calendar" aria-hidden="true"></span>
                    <div><h2>Plan together</h2><p>Keep birthdays, school runs, and Sunday lunch on a shared calendar.</p></div>
                </article>
                <article class="feature">
                    <span class="feature-icon feature-list" aria-hidden="true"></span>
                    <div><h2>Share the errands</h2><p>Add to the grocery list and see what’s already in the trolley.</p></div>
                </article>
                <article class="feature">
                    <span class="feature-icon feature-chat" aria-hidden="true"></span>
                    <div><h2>Keep in touch</h2><p>Send a quick update or a photo in your family chat.</p></div>
                </article>
            </section>
        </main>

        <footer class="public-footer">
            <span>Relatives</span>
            <a href="/legal/">Privacy &amp; terms</a>
        </footer>
    </div>
</body>
</html>
