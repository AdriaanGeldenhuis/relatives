<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, no-cache');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f3f2e9">
    <title>Home offline - Relatives</title>
    <link rel="icon" href="/favicon.ico">
    <style><?php
        readfile(__DIR__ . '/css/home.css');
        readfile(__DIR__ . '/../shared/css/family-surfaces.css');
        readfile(__DIR__ . '/../shared/css/action-controls.css');
    ?></style>
    <style>
        body.offline-home {
            --family-canvas: #f6f3ec;
            --family-paper: #fffdf8;
            --family-ink: #202e40;
            --family-muted: #536172;
            --family-line: #ded9cf;
            --family-green: #28566d;
            --family-lime: #ffe3ba;
        }
        .offline-home .family-main .family-hero {
            --control-primary: #ffe3ba;
            --control-primary-ink: #202e40;
            --control-primary-hover: #fff1db;
            background: linear-gradient(115deg, #182639, #304961);
            border-color: #2c4158;
        }
        .offline-home .family-hero .family-hero-summary { color: #e1e7ee; }
        .offline-home .launch-chip { background: #e3ebf2; }
    </style>
</head>
<body class="home-page family-page offline-home" data-area="home">
    <header class="offline-header"><span class="home-wordmark">Relatives<span>.</span></span></header>
    <main class="main-content family-main">
        <div class="hub">
            <section class="hero family-hero" data-time="morning" aria-labelledby="offline-title">
                <div class="hero-main">
                    <div class="hero-greeting family-hero-content">
                        <p class="hero-kicker"><span id="heroDate"></span></p>
                        <h1 class="family-hero-title" id="offline-title">Relatives Home</h1>
                        <p class="family-hero-summary" id="offline-app-status" role="status">You’re offline. Family activity and apps will be available when you reconnect.</p>
                        <div class="family-actions">
                            <a class="family-button family-button-primary" href="/home/">Try again <span aria-hidden="true">↻</span></a>
                        </div>
                    </div>
                    <div class="hero-clock" aria-hidden="true">
                        <div class="clock-ring" id="clockRing">
                            <div class="clock-core">
                                <span class="clock-hm" id="clockHM"></span>
                                <span class="clock-sec" id="clockSec"></span>
                            </div>
                        </div>
                        <span class="clock-cap" id="dayCap"></span>
                    </div>
                </div>
            </section>
            <h2 class="home-section-title family-section-title">Family apps</h2>
            <ul class="launcher offline-apps" aria-label="Family apps available online">
                <li><a class="launch-tile" data-app-url="/tracking/app/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">📍</span></span><span class="launch-label">Tracking</span></a></li>
                <li><a class="launch-tile" data-app-url="/weather/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">🌤️</span></span><span class="launch-label">Weather</span></a></li>
                <li><a class="launch-tile" data-app-url="/notes/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">📝</span></span><span class="launch-label">Notes</span></a></li>
                <li><a class="launch-tile" data-app-url="/shopping/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">🛒</span></span><span class="launch-label">Shopping</span></a></li>
                <li><a class="launch-tile" data-app-url="/calendar/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">📅</span></span><span class="launch-label">Calendar</span></a></li>
                <li><a class="launch-tile" data-app-url="/schedule/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">⏰</span></span><span class="launch-label">Schedule</span></a></li>
                <li><a class="launch-tile" data-app-url="/holiday_traveling/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">✈️</span></span><span class="launch-label">Travel</span></a></li>
                <li><a class="launch-tile" data-app-url="/games/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">🎮</span></span><span class="launch-label">Games</span></a></li>
                <li><a class="launch-tile" data-app-url="/messages/" role="link" aria-disabled="true"><span class="launch-chip" aria-hidden="true"><span class="launch-icon">💬</span></span><span class="launch-label">Messages</span></a></li>
            </ul>
        </div>
    </main>
    <script><?php readfile(__DIR__ . '/js/home.js'); ?></script>
</body>
</html>
