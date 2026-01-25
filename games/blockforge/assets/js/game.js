/* =============================================
   BLOCKFORGE - Main Game Orchestrator
   ============================================= */

(function() {
    'use strict';

    var settings = {};
    var animFrame = null;
    var lastTime = 0;
    var menuAnimFrame = null;
    var currentMode = 'solo';
    var familyBoardData = null;

    // Initialize everything
    function boot() {
        BlockStorage.init().then(function() {
            settings = BlockStorage.getSettings();
            applySettings(settings);

            BlockUI.init();
            BlockAudio.init();
            BlockRenderer.init('game-canvas');
            BlockInput.init({ controls: settings.controls });

            setupMenuListeners();
            setupGameListeners();
            setupOverlayListeners();

            updateOnlineStatus();
            startMenuAnimation();

            // Try sync on load
            BlockAPI.syncAll();

            // Periodic online check
            setInterval(updateOnlineStatus, 5000);
        });
    }

    function applySettings(s) {
        document.body.setAttribute('data-theme', s.theme || 'neon-dark');
        BlockRenderer.setTheme(s.theme || 'neon-dark');
        BlockAudio.setEnabled(s.sound);
        BlockInput.showControls(s.controls);
    }

    // Menu animation
    function startMenuAnimation() {
        var menuCanvas = document.getElementById('menu-bg-canvas');
        if (!menuCanvas) return;

        function animateMenu() {
            BlockRenderer.renderMenuBg(menuCanvas);
            menuAnimFrame = requestAnimationFrame(animateMenu);
        }
        animateMenu();
    }

    function stopMenuAnimation() {
        if (menuAnimFrame) {
            cancelAnimationFrame(menuAnimFrame);
            menuAnimFrame = null;
        }
    }

    // Online status
    function updateOnlineStatus() {
        var online = BlockAPI.getOnlineStatus();
        BlockStorage.getUnsyncedScores().then(function(scores) {
            var synced = scores.length === 0;
            BlockUI.updateStatusBadges(online, synced);
        }).catch(function() {
            BlockUI.updateStatusBadges(online, true);
        });
    }

    // ===== MENU LISTENERS =====
    function setupMenuListeners() {
        // Mode cards
        var modeCards = document.querySelectorAll('.mode-card');
        modeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                var mode = card.getAttribute('data-mode');
                BlockAudio.menuSelect();
                haptic([10]);
                startMode(mode);
            });
        });

        // Leaderboard button
        document.getElementById('btn-leaderboard').addEventListener('click', function() {
            BlockAudio.menuSelect();
            openLeaderboard();
        });

        // Settings button
        document.getElementById('btn-settings').addEventListener('click', function() {
            BlockAudio.menuSelect();
            BlockUI.showSettings(settings);
        });

        // Back to hub
        document.getElementById('btn-back-hub').addEventListener('click', function() {
            window.location.href = '/games/';
        });
    }

    // ===== START GAME MODES =====
    function startMode(mode) {
        currentMode = mode;

        if (mode === 'solo') {
            startSoloGame();
        } else if (mode === 'daily') {
            startDailyGame();
        } else if (mode === 'family') {
            openFamilyScreen();
        }
    }

    function startSoloGame() {
        BlockEngine.init({ mode: 'solo', seed: Date.now().toString() });
        setupEngineCallbacks();
        stopMenuAnimation();
        BlockUI.showScreen('game');
        BlockUI.hideAllOverlays();
        BlockInput.setEnabled(true);
        BlockRenderer.resize();

        setTimeout(function() {
            BlockEngine.start();
            startGameLoop();
        }, 300);
    }

    function startDailyGame() {
        if (BlockStorage.isDailyPlayed()) {
            alert('You already played today\'s Daily Challenge! Come back tomorrow.');
            return;
        }

        BlockAPI.getDaily().then(function(data) {
            BlockEngine.init({
                mode: 'daily',
                seed: data.seed,
                dailyRules: data.mode_rules
            });
            setupEngineCallbacks();
            stopMenuAnimation();
            BlockUI.showScreen('game');
            BlockUI.hideAllOverlays();
            BlockInput.setEnabled(true);
            BlockRenderer.resize();

            setTimeout(function() {
                BlockEngine.start();
                startGameLoop();
            }, 300);
        });
    }

    function openFamilyScreen() {
        BlockUI.showScreen('family');
        stopMenuAnimation();

        BlockAPI.getFamilyBoard().then(function(data) {
            familyBoardData = data;
            BlockUI.updateFamilyScreen(data);

            // Render the current family board
            var familyCanvas = document.getElementById('family-canvas');
            var grid = data.grid || createEmptyGrid();
            BlockRenderer.renderFamilyBoard(familyCanvas, grid, 10, 20);
        });
    }

    function startFamilyGame() {
        if (BlockStorage.isFamilyTurnUsed()) {
            alert('You already used your family turn today!');
            return;
        }

        var grid = null;
        if (familyBoardData && familyBoardData.grid) {
            grid = familyBoardData.grid;
        }

        BlockEngine.init({
            mode: 'family',
            seed: Date.now().toString(),
            grid: grid ? padGrid(grid) : null
        });
        setupEngineCallbacks();
        BlockUI.showScreen('game');
        BlockUI.hideAllOverlays();
        BlockInput.setEnabled(true);
        BlockRenderer.resize();

        setTimeout(function() {
            BlockEngine.start();
            startGameLoop();
        }, 300);
    }

    function createEmptyGrid() {
        var grid = [];
        for (var r = 0; r < 20; r++) {
            grid.push(new Array(10).fill(null));
        }
        return grid;
    }

    function padGrid(grid) {
        // Add hidden rows on top
        var padded = [];
        for (var r = 0; r < 4; r++) {
            padded.push(new Array(10).fill(null));
        }
        for (var r2 = 0; r2 < 20; r2++) {
            padded.push(grid[r2] ? grid[r2].slice() : new Array(10).fill(null));
        }
        return padded;
    }

    // ===== ENGINE CALLBACKS =====
    function setupEngineCallbacks() {
        BlockEngine.onLineClear = function(rows, count, combo, earnedScore) {
            BlockAudio.lineClear(count);
            haptic(count >= 4 ? [50, 30, 50] : [20]);
            BlockRenderer.startLineClear(rows.map(function(r) { return r - 4; }));

            // Particles for each cleared row
            var cs = BlockRenderer.getCellSize();
            rows.forEach(function(row) {
                var adjustedRow = row - 4;
                BlockParticles.lineClearBurst(adjustedRow, 10, cs, 0, 0,
                    count >= 4 ? '#ffee00' : '#00f5ff');
            });
        };

        BlockEngine.onLevelUp = function(level) {
            BlockAudio.levelUp();
            haptic([30, 20, 30, 20, 50]);
            var size = BlockRenderer.getCanvasSize();
            BlockParticles.levelUpBurst(size.width / 2, size.height / 2);
        };

        BlockEngine.onCombo = function(combo, score) {
            BlockAudio.combo(combo);
            haptic([15]);
            BlockUI.showComboPopup(combo, score);

            var size = BlockRenderer.getCanvasSize();
            BlockParticles.comboStar(size.width / 2, size.height / 3, combo);
        };

        BlockEngine.onHardDrop = function(piece, startY, distance) {
            BlockAudio.hardDrop();
            haptic([30, 10, 20]);

            var cs = BlockRenderer.getCellSize();
            var shape = BlockPieces.getShape(piece.name, piece.rotation);
            var color = BlockPieces.getColor(piece.name);

            for (var c = 0; c < shape[0].length; c++) {
                for (var r = shape.length - 1; r >= 0; r--) {
                    if (shape[r][c]) {
                        var gx = piece.x + c;
                        var adjustedStart = (startY + r) - 4;
                        var adjustedEnd = (piece.y + r) - 4;
                        BlockRenderer.addDropTrail(gx, adjustedStart, adjustedEnd, color);
                        BlockRenderer.addSquash(gx, adjustedEnd);

                        var px = gx * cs + cs / 2;
                        var py = adjustedEnd * cs + cs;
                        BlockParticles.hardDropTrail(px, py, color);
                        break;
                    }
                }
            }
        };

        BlockEngine.onLock = function(piece) {
            BlockAudio.lock();
        };

        BlockEngine.onGameOver = function(result) {
            BlockAudio.gameOver();
            haptic([100, 50, 100]);
            stopGameLoop();

            // Update stats and streak
            BlockStorage.updateStats(result);
            BlockStorage.updateStreak();

            if (currentMode === 'daily') {
                BlockStorage.markDailyPlayed();
            }

            // Check achievements
            checkAchievements(result);

            // Show results
            setTimeout(function() {
                BlockUI.showResults(result);

                // Submit score
                BlockAPI.submitScore({
                    mode: result.mode,
                    score: result.score,
                    lines_cleared: result.lines,
                    level_reached: result.level,
                    duration_ms: result.duration,
                    seed: result.seed
                }).then(function(response) {
                    if (response && response.ranks) {
                        BlockUI.updateResultsRank(response.ranks);
                    }
                    updateOnlineStatus();
                });

                // Submit family turn
                if (result.mode === 'family' && result.familyActions) {
                    BlockAPI.submitFamilyTurn({
                        date: new Date().toISOString().split('T')[0],
                        actions: result.familyActions,
                        lines_cleared: result.familyLinesDelta,
                        score_delta: result.familyScoreDelta
                    });
                    BlockStorage.markFamilyTurnUsed();
                }
            }, 500);
        };
    }

    // ===== GAME LOOP =====
    function startGameLoop() {
        lastTime = performance.now();
        function loop(time) {
            var dt = Math.min((time - lastTime) / 1000, 0.05); // cap at 50ms
            lastTime = time;

            BlockEngine.update(dt);

            var state = BlockEngine.getState();
            BlockUI.updateHUD(state);
            BlockRenderer.render(state);

            animFrame = requestAnimationFrame(loop);
        }
        animFrame = requestAnimationFrame(loop);
    }

    function stopGameLoop() {
        if (animFrame) {
            cancelAnimationFrame(animFrame);
            animFrame = null;
        }
    }

    // ===== GAME INPUT CALLBACKS =====
    function setupGameListeners() {
        BlockInput.setCallbacks({
            moveLeft: function() {
                if (BlockEngine.moveLeft()) {
                    BlockAudio.move();
                }
            },
            moveRight: function() {
                if (BlockEngine.moveRight()) {
                    BlockAudio.move();
                }
            },
            softDrop: function() {
                if (BlockEngine.moveDown()) {
                    BlockAudio.softDrop();
                }
            },
            hardDrop: function() {
                BlockEngine.hardDrop();
            },
            rotate: function() {
                if (BlockEngine.rotate()) {
                    BlockAudio.rotate();
                    haptic([5]);
                }
            },
            pause: function() {
                if (BlockEngine.isRunning() && !BlockEngine.isGameOver()) {
                    if (BlockEngine.isPaused()) {
                        BlockEngine.resume();
                        BlockUI.hideOverlay('pause');
                        startGameLoop();
                    } else {
                        BlockEngine.pause();
                        stopGameLoop();
                        BlockUI.showPause(BlockEngine.getState());
                    }
                }
            }
        });
    }

    // ===== OVERLAY LISTENERS =====
    function setupOverlayListeners() {
        // Pause overlay
        document.getElementById('btn-resume').addEventListener('click', function() {
            BlockEngine.resume();
            BlockUI.hideOverlay('pause');
            startGameLoop();
            BlockAudio.menuSelect();
        });

        document.getElementById('btn-restart').addEventListener('click', function() {
            BlockUI.hideAllOverlays();
            BlockAudio.menuSelect();
            startMode(currentMode);
        });

        document.getElementById('btn-quit').addEventListener('click', function() {
            stopGameLoop();
            BlockUI.hideAllOverlays();
            BlockUI.showScreen('menu');
            startMenuAnimation();
            BlockAudio.menuSelect();
            BlockParticles.clear();
        });

        // Results overlay
        document.getElementById('btn-share').addEventListener('click', function() {
            var result = BlockEngine.getResult();
            BlockShareCard.generate(result);
            BlockShareCard.save();
            BlockAudio.menuSelect();
        });

        document.getElementById('btn-play-again').addEventListener('click', function() {
            BlockUI.hideAllOverlays();
            BlockAudio.menuSelect();
            BlockParticles.clear();
            startMode(currentMode);
        });

        document.getElementById('btn-menu').addEventListener('click', function() {
            BlockUI.hideAllOverlays();
            BlockUI.showScreen('menu');
            startMenuAnimation();
            BlockAudio.menuSelect();
            BlockParticles.clear();
        });

        // Settings overlay
        document.getElementById('btn-settings-close').addEventListener('click', function() {
            settings = BlockUI.getSettingsValues();
            BlockStorage.saveSettings(settings);
            applySettings(settings);
            BlockUI.hideOverlay('settings');
            BlockAudio.menuSelect();
        });

        // Leaderboard overlay
        document.getElementById('btn-lb-close').addEventListener('click', function() {
            BlockUI.hideOverlay('leaderboard');
            BlockAudio.menuSelect();
        });

        // Leaderboard tabs
        document.querySelectorAll('.lb-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.lb-tab').forEach(function(t) { t.classList.remove('active'); });
                tab.classList.add('active');
                loadLeaderboard();
            });
        });

        document.querySelectorAll('.lb-range-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.lb-range-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                loadLeaderboard();
            });
        });

        // Family screen
        document.getElementById('btn-family-back').addEventListener('click', function() {
            BlockUI.showScreen('menu');
            startMenuAnimation();
            BlockAudio.menuSelect();
        });

        document.getElementById('btn-family-play').addEventListener('click', function() {
            BlockAudio.menuSelect();
            haptic([10]);
            startFamilyGame();
        });
    }

    // ===== LEADERBOARD =====
    function openLeaderboard() {
        BlockUI.showLeaderboard();
        loadLeaderboard();
    }

    function loadLeaderboard() {
        var activeTab = document.querySelector('.lb-tab.active');
        var activeRange = document.querySelector('.lb-range-btn.active');
        var mode = activeTab ? activeTab.getAttribute('data-tab') : 'solo';
        var range = activeRange ? activeRange.getAttribute('data-range') : 'today';

        document.getElementById('lb-list').innerHTML = '<div class="lb-empty">Loading...</div>';

        BlockAPI.getLeaderboard(mode, range).then(function(data) {
            BlockUI.renderLeaderboard(data);
        }).catch(function() {
            document.getElementById('lb-list').innerHTML = '<div class="lb-empty">Offline - scores cached locally</div>';
        });
    }

    // ===== ACHIEVEMENTS =====
    function checkAchievements(result) {
        if (result.score >= 1000) BlockStorage.unlockAchievement('score_1k');
        if (result.score >= 10000) BlockStorage.unlockAchievement('score_10k');
        if (result.score >= 50000) BlockStorage.unlockAchievement('score_50k');
        if (result.lines >= 10) BlockStorage.unlockAchievement('lines_10');
        if (result.lines >= 50) BlockStorage.unlockAchievement('lines_50');
        if (result.lines >= 100) BlockStorage.unlockAchievement('lines_100');
        if (result.maxCombo >= 3) BlockStorage.unlockAchievement('combo_3');
        if (result.maxCombo >= 5) BlockStorage.unlockAchievement('combo_5');
        if (result.maxCombo >= 10) BlockStorage.unlockAchievement('combo_10');
        if (result.level >= 5) BlockStorage.unlockAchievement('level_5');
        if (result.level >= 10) BlockStorage.unlockAchievement('level_10');
        if (result.level >= 20) BlockStorage.unlockAchievement('level_20');

        var streak = BlockStorage.getStreak();
        if (streak.count >= 3) BlockStorage.unlockAchievement('streak_3');
        if (streak.count >= 7) BlockStorage.unlockAchievement('streak_7');
        if (streak.count >= 30) BlockStorage.unlockAchievement('streak_30');
    }

    // ===== HAPTIC =====
    function haptic(pattern) {
        if (settings.haptics) {
            BlockInput.vibrate(pattern);
        }
    }

    // Boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Resume audio on first interaction
    document.addEventListener('touchstart', function() { BlockAudio.resume(); }, { once: true });
    document.addEventListener('click', function() { BlockAudio.resume(); }, { once: true });

    // Handle resize
    window.addEventListener('resize', function() {
        if (BlockEngine.isRunning()) {
            BlockRenderer.resize();
        }
    });

    // Handle visibility change (auto-pause)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden && BlockEngine.isRunning() && !BlockEngine.isPaused() && !BlockEngine.isGameOver()) {
            BlockEngine.pause();
            stopGameLoop();
            BlockUI.showPause(BlockEngine.getState());
        }
    });

})();
