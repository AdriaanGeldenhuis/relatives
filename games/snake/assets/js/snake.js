/**
 * Snake Classic - Game Engine
 * Nokia 3310 style snake game with mobile touch controls.
 */

const SnakeGame = (function() {
    'use strict';

    // Game configuration
    const CONFIG = {
        GRID_SIZE: 20,           // 20x20 grid
        INITIAL_SPEED: 150,      // ms per move
        MIN_SPEED: 60,           // fastest speed
        SPEED_INCREASE: 5,       // ms faster per food
        FOODS_PER_SPEEDUP: 3,    // speed up every N foods
        CELL_PADDING: 1,         // gap between cells
        SWIPE_THRESHOLD: 30,     // min swipe distance in px
        SWIPE_MAX_TIME: 300      // max ms for swipe
    };

    // Theme Color Configurations
    const THEMES = {
        neon: {
            name: 'Neon Retro',
            BACKGROUND: '#080810',
            BACKGROUND_GRADIENT: ['#080810', '#0a0a14', '#080810'],
            GRID: '#12121f',
            SNAKE_HEAD: '#00ffa3',
            SNAKE_HEAD_HIGHLIGHT: '#5fffd4',
            SNAKE_HEAD_SHADOW: '#3db892',
            SNAKE_HEAD_GLOW: 'rgba(0, 255, 163, 0.4)',
            SNAKE_BODY: '#4ecca3',
            SNAKE_BODY_GRADIENT: ['#4ecca3', '#3db892', '#2ea881'],
            FOOD: '#ff6b6b',
            FOOD_INNER: '#ff4757',
            FOOD_HIGHLIGHT: '#ff8a8a',
            FOOD_GLOW: 'rgba(255, 107, 107, 0.5)',
            FOOD_GLOW_OUTER: 'rgba(255, 71, 87, 0.2)',
            EYE_COLOR: '#fff',
            useGlow: true
        },
        realistic: {
            name: 'Realistic',
            // Grass field background
            BACKGROUND: '#2d5a1d',
            BACKGROUND_GRADIENT: ['#3d6b2d', '#2d5a1d', '#1d4a0d'],
            GRID: '#2a5518',
            // Real snake colors - dark green/brown python look
            SNAKE_HEAD: '#3d4a2a',
            SNAKE_HEAD_HIGHLIGHT: '#5a6b3a',
            SNAKE_HEAD_SHADOW: '#2a3520',
            SNAKE_HEAD_GLOW: 'transparent',
            SNAKE_BODY: '#4a5a32',
            SNAKE_BODY_GRADIENT: ['#5a6b3a', '#4a5a32', '#3a4a28'],
            SNAKE_BELLY: '#8b9a6b',
            SNAKE_PATTERN: '#2a3520',
            // Shiny red apple
            FOOD: '#cc2233',
            FOOD_INNER: '#991122',
            FOOD_HIGHLIGHT: '#ff4455',
            FOOD_GLOW: 'transparent',
            FOOD_GLOW_OUTER: 'transparent',
            EYE_COLOR: '#1a1a00',
            EYE_OUTER: '#ffdd00',
            useGlow: false,
            // Enable realistic rendering
            realisticMode: true,
            snakePattern: true,
            foodStyle: 'apple'
        },
        classic: {
            name: 'Nokia Classic',
            BACKGROUND: '#9bbc0f',
            BACKGROUND_GRADIENT: ['#9bbc0f', '#8bac00', '#9bbc0f'],
            GRID: '#8bac00',
            SNAKE_HEAD: '#0f380f',
            SNAKE_HEAD_HIGHLIGHT: '#306230',
            SNAKE_HEAD_SHADOW: '#0a2a0a',
            SNAKE_HEAD_GLOW: 'rgba(15, 56, 15, 0.2)',
            SNAKE_BODY: '#0f380f',
            SNAKE_BODY_GRADIENT: ['#0f380f', '#0f380f', '#0f380f'],
            FOOD: '#0f380f',
            FOOD_INNER: '#0f380f',
            FOOD_HIGHLIGHT: '#306230',
            FOOD_GLOW: 'rgba(15, 56, 15, 0.1)',
            FOOD_GLOW_OUTER: 'transparent',
            EYE_COLOR: '#9bbc0f',
            useGlow: false,
            // Classic theme uses simple blocks
            blockStyle: true
        }
    };

    // Current theme (default to neon)
    let currentTheme = 'neon';
    let COLORS = THEMES.neon;

    // Direction vectors
    const DIRECTIONS = {
        UP: { x: 0, y: -1 },
        DOWN: { x: 0, y: 1 },
        LEFT: { x: -1, y: 0 },
        RIGHT: { x: 1, y: 0 }
    };

    // Opposite directions (to prevent instant reversal)
    const OPPOSITES = {
        UP: 'DOWN',
        DOWN: 'UP',
        LEFT: 'RIGHT',
        RIGHT: 'LEFT'
    };

    // Game state
    let canvas, ctx;
    let cellSize = 0;
    let gameState = 'idle'; // idle, playing, paused, gameover
    let snake = [];
    let food = null;
    let direction = 'RIGHT';
    let nextDirection = 'RIGHT';
    let score = 0;
    let foodsEaten = 0;
    let currentSpeed = CONFIG.INITIAL_SPEED;
    let lastMoveTime = 0;
    let gameStartTime = null;
    let animationFrameId = null;

    // Touch handling
    let touchStartX = 0;
    let touchStartY = 0;
    let touchStartTime = 0;

    // UI Elements
    const ui = {};

    /**
     * Initialize the game
     */
    function init() {
        // Get canvas
        canvas = document.getElementById('game-canvas');
        ctx = canvas.getContext('2d');

        // Cache UI elements
        ui.currentScore = document.getElementById('current-score');
        ui.bestScore = document.getElementById('best-score');
        ui.familyBest = document.getElementById('family-best');
        ui.globalBest = document.getElementById('global-best');
        ui.startScreen = document.getElementById('start-screen');
        ui.pauseScreen = document.getElementById('pause-screen');
        ui.gameoverScreen = document.getElementById('gameover-screen');
        ui.leaderboardScreen = document.getElementById('leaderboard-screen');
        ui.finalScoreValue = document.getElementById('final-score-value');
        ui.scoreStatus = document.getElementById('score-status');
        ui.syncIndicator = document.getElementById('sync-indicator');

        // Setup canvas size
        resizeCanvas();
        window.addEventListener('resize', debounce(resizeCanvas, 100));

        // Setup controls
        setupTouchControls();
        setupDPadControls();
        setupKeyboardControls();
        setupButtonListeners();

        // Initialize API and load data
        SnakeAPI.init();
        SnakeAPI.onSyncStatusChange(updateSyncIndicator);

        // Initialize theme
        initTheme();
        setupThemeControls();

        // Load initial scores
        updateScoreDisplay();
        loadLeaderboardData();

        // Draw initial state
        draw();

        console.log('Snake game initialized');
    }

    /**
     * Resize canvas to fit container while maintaining square aspect ratio
     */
    function resizeCanvas() {
        const container = canvas.parentElement;
        const containerWidth = container.clientWidth - 16; // padding
        const containerHeight = container.clientHeight - 16;

        // Calculate size to fit, keeping it square
        const size = Math.min(containerWidth, containerHeight, 400);

        // Set display size
        canvas.style.width = size + 'px';
        canvas.style.height = size + 'px';

        // Set actual canvas resolution (2x for retina)
        const dpr = window.devicePixelRatio || 1;
        canvas.width = size * dpr;
        canvas.height = size * dpr;

        // Scale context
        ctx.scale(dpr, dpr);

        // Calculate cell size
        cellSize = Math.floor(size / CONFIG.GRID_SIZE);

        // Redraw
        draw();
    }

    /**
     * Setup touch/swipe controls on canvas
     */
    function setupTouchControls() {
        canvas.addEventListener('touchstart', handleTouchStart, { passive: false });
        canvas.addEventListener('touchmove', handleTouchMove, { passive: false });
        canvas.addEventListener('touchend', handleTouchEnd, { passive: false });
    }

    function handleTouchStart(e) {
        e.preventDefault();
        const touch = e.touches[0];
        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
        touchStartTime = Date.now();
    }

    function handleTouchMove(e) {
        e.preventDefault();
    }

    function handleTouchEnd(e) {
        e.preventDefault();

        const touch = e.changedTouches[0];
        const deltaX = touch.clientX - touchStartX;
        const deltaY = touch.clientY - touchStartY;
        const deltaTime = Date.now() - touchStartTime;

        // Check if it's a valid swipe
        if (deltaTime > CONFIG.SWIPE_MAX_TIME) return;

        const absX = Math.abs(deltaX);
        const absY = Math.abs(deltaY);

        if (absX < CONFIG.SWIPE_THRESHOLD && absY < CONFIG.SWIPE_THRESHOLD) {
            // Tap - could be used for pause in future
            return;
        }

        // Determine swipe direction
        if (absX > absY) {
            // Horizontal swipe
            setDirection(deltaX > 0 ? 'RIGHT' : 'LEFT');
        } else {
            // Vertical swipe
            setDirection(deltaY > 0 ? 'DOWN' : 'UP');
        }
    }

    /**
     * Setup D-Pad button controls - optimized for instant response
     */
    function setupDPadControls() {
        const buttons = {
            'btn-up': 'UP',
            'btn-down': 'DOWN',
            'btn-left': 'LEFT',
            'btn-right': 'RIGHT'
        };

        Object.entries(buttons).forEach(([id, dir]) => {
            const btn = document.getElementById(id);
            if (btn) {
                // Prevent default touch behaviors for instant response
                btn.style.touchAction = 'manipulation';

                // Use pointerdown for fastest response (works for touch & mouse)
                btn.addEventListener('pointerdown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    btn.classList.add('pressed');
                    setDirection(dir);
                    // Haptic feedback if available
                    if (navigator.vibrate) navigator.vibrate(10);
                }, { passive: false });

                btn.addEventListener('pointerup', () => {
                    btn.classList.remove('pressed');
                }, { passive: true });

                btn.addEventListener('pointerleave', () => {
                    btn.classList.remove('pressed');
                }, { passive: true });

                // Prevent context menu on long press
                btn.addEventListener('contextmenu', (e) => e.preventDefault());
            }
        });

        // Pause button
        const pauseBtn = document.getElementById('pause-btn');
        if (pauseBtn) {
            pauseBtn.style.touchAction = 'manipulation';
            pauseBtn.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                togglePause();
                if (navigator.vibrate) navigator.vibrate(10);
            }, { passive: false });
        }
    }

    /**
     * Setup keyboard controls (for testing/desktop)
     */
    function setupKeyboardControls() {
        const keyMap = {
            'ArrowUp': 'UP',
            'ArrowDown': 'DOWN',
            'ArrowLeft': 'LEFT',
            'ArrowRight': 'RIGHT',
            'w': 'UP',
            's': 'DOWN',
            'a': 'LEFT',
            'd': 'RIGHT'
        };

        document.addEventListener('keydown', (e) => {
            if (keyMap[e.key]) {
                e.preventDefault();
                setDirection(keyMap[e.key]);
            } else if (e.key === ' ' || e.key === 'Escape') {
                e.preventDefault();
                if (gameState === 'playing') {
                    togglePause();
                } else if (gameState === 'paused') {
                    resumeGame();
                }
            }
        });
    }

    /**
     * Setup UI button listeners
     */
    function setupButtonListeners() {
        document.getElementById('start-btn').addEventListener('click', startGame);
        document.getElementById('resume-btn').addEventListener('click', resumeGame);
        document.getElementById('restart-btn').addEventListener('click', startGame);
        document.getElementById('playagain-btn').addEventListener('click', startGame);
        document.getElementById('leaderboard-btn').addEventListener('click', showLeaderboards);
        document.getElementById('close-leaderboard-btn').addEventListener('click', hideLeaderboards);

        // Tab buttons
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                loadLeaderboardData(btn.dataset.tab);
            });
        });
    }

    /**
     * Initialize theme from localStorage
     */
    function initTheme() {
        const savedTheme = localStorage.getItem('snake_theme') || 'neon';
        setTheme(savedTheme);
    }

    /**
     * Set the active theme
     */
    function setTheme(themeName) {
        if (!THEMES[themeName]) {
            themeName = 'neon';
        }

        currentTheme = themeName;
        COLORS = THEMES[themeName];

        // Update HTML data-theme attribute for CSS
        document.documentElement.setAttribute('data-theme', themeName);

        // Save to localStorage
        localStorage.setItem('snake_theme', themeName);

        // Update theme option buttons
        document.querySelectorAll('.theme-option').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.theme === themeName);
        });

        // Redraw canvas with new theme
        draw();
    }

    /**
     * Setup theme control event listeners
     */
    function setupThemeControls() {
        // Theme button opens theme modal
        const themeBtn = document.getElementById('theme-btn');
        const themeScreen = document.getElementById('theme-screen');
        const closeThemeBtn = document.getElementById('close-theme-btn');

        if (themeBtn && themeScreen) {
            themeBtn.addEventListener('click', () => {
                themeScreen.classList.remove('hidden');
            });
        }

        if (closeThemeBtn && themeScreen) {
            closeThemeBtn.addEventListener('click', () => {
                themeScreen.classList.add('hidden');
            });
        }

        // Theme option buttons
        document.querySelectorAll('.theme-option').forEach(btn => {
            btn.addEventListener('click', () => {
                const themeName = btn.dataset.theme;
                if (themeName && THEMES[themeName]) {
                    setTheme(themeName);
                    // Close modal after selection
                    if (themeScreen) {
                        themeScreen.classList.add('hidden');
                    }
                }
            });
        });

        // Mark current theme as active
        document.querySelectorAll('.theme-option').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.theme === currentTheme);
        });
    }

    /**
     * Set new direction (with validation)
     */
    function setDirection(newDir) {
        if (gameState !== 'playing') return;

        // Prevent reversing direction
        if (OPPOSITES[newDir] === direction) return;

        nextDirection = newDir;
    }

    /**
     * Start a new game
     */
    function startGame() {
        // Reset state
        snake = [
            { x: Math.floor(CONFIG.GRID_SIZE / 2), y: Math.floor(CONFIG.GRID_SIZE / 2) }
        ];
        direction = 'RIGHT';
        nextDirection = 'RIGHT';
        score = 0;
        foodsEaten = 0;
        currentSpeed = CONFIG.INITIAL_SPEED;
        gameStartTime = new Date();

        // Spawn first food
        spawnFood();

        // Update UI
        updateScoreDisplay();
        hideAllOverlays();

        // Start game loop
        gameState = 'playing';
        lastMoveTime = performance.now();
        gameLoop();
    }

    /**
     * Pause the game
     */
    function togglePause() {
        if (gameState === 'playing') {
            gameState = 'paused';
            ui.pauseScreen.classList.remove('hidden');
            if (animationFrameId) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }
        }
    }

    /**
     * Resume the game
     */
    function resumeGame() {
        if (gameState === 'paused') {
            gameState = 'playing';
            ui.pauseScreen.classList.add('hidden');
            lastMoveTime = performance.now();
            gameLoop();
        }
    }

    /**
     * Game over
     */
    function endGame() {
        gameState = 'gameover';
        const gameEndTime = new Date();

        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
        }

        // Show game over screen
        ui.finalScoreValue.textContent = score;
        ui.scoreStatus.textContent = 'Saving...';
        ui.scoreStatus.className = 'score-status';
        ui.gameoverScreen.classList.remove('hidden');

        // Save score
        const scoreData = SnakeStorage.createScoreRecord(score, gameStartTime, gameEndTime);

        SnakeAPI.submitScoreWithFallback(scoreData).then(result => {
            ui.scoreStatus.textContent = result.message;
            ui.scoreStatus.classList.add(result.synced ? 'synced' : 'saved');
            updateSyncIndicator(SnakeAPI.getSyncStatus());

            // Refresh leaderboards
            loadLeaderboardData();
        });

        // Update display
        updateScoreDisplay();
    }

    /**
     * Main game loop
     */
    function gameLoop(timestamp) {
        if (gameState !== 'playing') return;

        animationFrameId = requestAnimationFrame(gameLoop);

        // Time-based movement
        if (!timestamp) timestamp = performance.now();
        const elapsed = timestamp - lastMoveTime;

        if (elapsed >= currentSpeed) {
            lastMoveTime = timestamp - (elapsed % currentSpeed);
            update();
        }

        draw();
    }

    /**
     * Update game state
     */
    function update() {
        // Apply direction change
        direction = nextDirection;

        // Calculate new head position
        const head = snake[0];
        const dir = DIRECTIONS[direction];
        const newHead = {
            x: head.x + dir.x,
            y: head.y + dir.y
        };

        // Check wall collision
        if (newHead.x < 0 || newHead.x >= CONFIG.GRID_SIZE ||
            newHead.y < 0 || newHead.y >= CONFIG.GRID_SIZE) {
            endGame();
            return;
        }

        // Check self collision
        for (let i = 0; i < snake.length; i++) {
            if (snake[i].x === newHead.x && snake[i].y === newHead.y) {
                endGame();
                return;
            }
        }

        // Move snake
        snake.unshift(newHead);

        // Check food collision
        if (food && newHead.x === food.x && newHead.y === food.y) {
            // Eat food (don't remove tail)
            score += 10;
            foodsEaten++;

            // Speed up
            if (foodsEaten % CONFIG.FOODS_PER_SPEEDUP === 0) {
                currentSpeed = Math.max(CONFIG.MIN_SPEED, currentSpeed - CONFIG.SPEED_INCREASE);
            }

            // Spawn new food
            spawnFood();

            // Update score display
            ui.currentScore.textContent = score;
        } else {
            // Remove tail
            snake.pop();
        }
    }

    /**
     * Spawn food at random location
     */
    function spawnFood() {
        const emptyCells = [];

        // Find all empty cells
        for (let x = 0; x < CONFIG.GRID_SIZE; x++) {
            for (let y = 0; y < CONFIG.GRID_SIZE; y++) {
                let occupied = false;
                for (let i = 0; i < snake.length; i++) {
                    if (snake[i].x === x && snake[i].y === y) {
                        occupied = true;
                        break;
                    }
                }
                if (!occupied) {
                    emptyCells.push({ x, y });
                }
            }
        }

        // Pick random empty cell
        if (emptyCells.length > 0) {
            food = emptyCells[Math.floor(Math.random() * emptyCells.length)];
        }
    }

    /**
     * Draw game - Theme-aware Premium Graphics
     */
    function draw() {
        const size = canvas.width / (window.devicePixelRatio || 1);

        // Clear canvas with theme gradient
        const bgGradient = ctx.createLinearGradient(0, 0, size, size);
        bgGradient.addColorStop(0, COLORS.BACKGROUND_GRADIENT[0]);
        bgGradient.addColorStop(0.5, COLORS.BACKGROUND_GRADIENT[1]);
        bgGradient.addColorStop(1, COLORS.BACKGROUND_GRADIENT[2]);
        ctx.fillStyle = bgGradient;
        ctx.fillRect(0, 0, size, size);

        // Draw realistic grass texture for realistic mode
        if (COLORS.realisticMode) {
            drawGrassBackground(size);
        } else {
            // Draw grid (subtle) for other themes
            ctx.fillStyle = COLORS.GRID;
            for (let x = 0; x < CONFIG.GRID_SIZE; x++) {
                for (let y = 0; y < CONFIG.GRID_SIZE; y++) {
                    ctx.fillRect(
                        x * cellSize + CONFIG.CELL_PADDING,
                        y * cellSize + CONFIG.CELL_PADDING,
                        cellSize - CONFIG.CELL_PADDING * 2,
                        cellSize - CONFIG.CELL_PADDING * 2
                    );
                }
            }
        }

        // Draw food based on theme
        if (food) {
            drawFood();
        }

        // Draw snake based on theme
        drawSnake();
    }

    /**
     * Draw realistic grass background
     */
    function drawGrassBackground(size) {
        // Base grass color already applied
        // Add grass texture with random grass blades
        const grassColors = ['#2a5518', '#3d6b2d', '#1d4a0d', '#4a7a3d', '#2d5a1d'];

        // Seed-based random for consistent grass pattern
        const seededRandom = (x, y) => {
            const n = Math.sin(x * 12.9898 + y * 78.233) * 43758.5453;
            return n - Math.floor(n);
        };

        // Draw grass patches
        for (let x = 0; x < CONFIG.GRID_SIZE; x++) {
            for (let y = 0; y < CONFIG.GRID_SIZE; y++) {
                const px = x * cellSize;
                const py = y * cellSize;

                // Subtle variation per cell
                const variation = seededRandom(x, y);
                ctx.fillStyle = grassColors[Math.floor(variation * grassColors.length)];
                ctx.globalAlpha = 0.3;
                ctx.fillRect(px, py, cellSize, cellSize);
                ctx.globalAlpha = 1;

                // Draw small grass blades
                const bladeCount = 3 + Math.floor(variation * 4);
                for (let i = 0; i < bladeCount; i++) {
                    const bx = px + seededRandom(x + i, y) * cellSize;
                    const by = py + cellSize - 2;
                    const height = 3 + seededRandom(x, y + i) * 5;

                    ctx.strokeStyle = grassColors[Math.floor(seededRandom(x * i, y) * grassColors.length)];
                    ctx.globalAlpha = 0.4;
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(bx, by);
                    ctx.lineTo(bx + (seededRandom(x, y * i) - 0.5) * 3, by - height);
                    ctx.stroke();
                }
                ctx.globalAlpha = 1;
            }
        }

        // Add subtle shadow/depth at edges
        const edgeGradient = ctx.createLinearGradient(0, 0, 0, size);
        edgeGradient.addColorStop(0, 'rgba(0,0,0,0.15)');
        edgeGradient.addColorStop(0.1, 'transparent');
        edgeGradient.addColorStop(0.9, 'transparent');
        edgeGradient.addColorStop(1, 'rgba(0,0,0,0.2)');
        ctx.fillStyle = edgeGradient;
        ctx.fillRect(0, 0, size, size);
    }

    /**
     * Draw food with theme-specific styling
     */
    function drawFood() {
        const foodCenterX = food.x * cellSize + cellSize / 2;
        const foodCenterY = food.y * cellSize + cellSize / 2;
        const fx = food.x * cellSize + CONFIG.CELL_PADDING + 1;
        const fy = food.y * cellSize + CONFIG.CELL_PADDING + 1;
        const fw = cellSize - CONFIG.CELL_PADDING * 2 - 2;

        // Classic theme - simple block
        if (COLORS.blockStyle) {
            ctx.fillStyle = COLORS.FOOD;
            ctx.fillRect(fx, fy, fw, fw);
            return;
        }

        // Realistic theme - 3D apple style
        if (COLORS.foodStyle === 'apple' && COLORS.realisticMode) {
            const appleRadius = fw * 0.45;

            // Ground shadow (ellipse on grass)
            ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
            ctx.beginPath();
            ctx.ellipse(foodCenterX + 2, foodCenterY + appleRadius + 2, appleRadius * 0.7, appleRadius * 0.2, 0, 0, Math.PI * 2);
            ctx.fill();

            // Apple body - main shape with 3D gradient
            const appleGradient = ctx.createRadialGradient(
                foodCenterX - appleRadius * 0.4, foodCenterY - appleRadius * 0.3, 0,
                foodCenterX, foodCenterY, appleRadius * 1.1
            );
            appleGradient.addColorStop(0, '#ff6666');      // Bright highlight
            appleGradient.addColorStop(0.2, '#ee3344');    // Light red
            appleGradient.addColorStop(0.5, '#cc2233');    // Main red
            appleGradient.addColorStop(0.8, '#991122');    // Dark red
            appleGradient.addColorStop(1, '#660011');      // Very dark edge
            ctx.fillStyle = appleGradient;

            // Draw apple shape (slightly squashed circle with indent at top)
            ctx.beginPath();
            ctx.moveTo(foodCenterX, foodCenterY - appleRadius * 0.85);
            // Right side curve
            ctx.bezierCurveTo(
                foodCenterX + appleRadius * 0.5, foodCenterY - appleRadius * 0.9,
                foodCenterX + appleRadius, foodCenterY - appleRadius * 0.3,
                foodCenterX + appleRadius, foodCenterY + appleRadius * 0.1
            );
            // Bottom right
            ctx.bezierCurveTo(
                foodCenterX + appleRadius, foodCenterY + appleRadius * 0.7,
                foodCenterX + appleRadius * 0.5, foodCenterY + appleRadius,
                foodCenterX, foodCenterY + appleRadius * 0.95
            );
            // Bottom left
            ctx.bezierCurveTo(
                foodCenterX - appleRadius * 0.5, foodCenterY + appleRadius,
                foodCenterX - appleRadius, foodCenterY + appleRadius * 0.7,
                foodCenterX - appleRadius, foodCenterY + appleRadius * 0.1
            );
            // Left side curve
            ctx.bezierCurveTo(
                foodCenterX - appleRadius, foodCenterY - appleRadius * 0.3,
                foodCenterX - appleRadius * 0.5, foodCenterY - appleRadius * 0.9,
                foodCenterX, foodCenterY - appleRadius * 0.85
            );
            ctx.fill();

            // Indent shadow at top
            ctx.fillStyle = 'rgba(0, 0, 0, 0.2)';
            ctx.beginPath();
            ctx.ellipse(foodCenterX, foodCenterY - appleRadius * 0.7, appleRadius * 0.25, appleRadius * 0.12, 0, 0, Math.PI * 2);
            ctx.fill();

            // Main highlight (wet/shiny look)
            ctx.fillStyle = 'rgba(255, 255, 255, 0.5)';
            ctx.beginPath();
            ctx.ellipse(
                foodCenterX - appleRadius * 0.35,
                foodCenterY - appleRadius * 0.25,
                appleRadius * 0.25,
                appleRadius * 0.15,
                -Math.PI / 5,
                0, Math.PI * 2
            );
            ctx.fill();

            // Small secondary highlight
            ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
            ctx.beginPath();
            ctx.arc(
                foodCenterX - appleRadius * 0.15,
                foodCenterY - appleRadius * 0.45,
                appleRadius * 0.08,
                0, Math.PI * 2
            );
            ctx.fill();

            // Stem - brown wood texture
            const stemGradient = ctx.createLinearGradient(
                foodCenterX - 2, foodCenterY - appleRadius,
                foodCenterX + 2, foodCenterY - appleRadius
            );
            stemGradient.addColorStop(0, '#3d2817');
            stemGradient.addColorStop(0.5, '#5d4037');
            stemGradient.addColorStop(1, '#3d2817');
            ctx.strokeStyle = stemGradient;
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(foodCenterX, foodCenterY - appleRadius * 0.75);
            ctx.quadraticCurveTo(
                foodCenterX + 2, foodCenterY - appleRadius * 1.1,
                foodCenterX + 1, foodCenterY - appleRadius * 1.25
            );
            ctx.stroke();

            // Leaf with gradient
            const leafGradient = ctx.createLinearGradient(
                foodCenterX + 2, foodCenterY - appleRadius * 1.1,
                foodCenterX + appleRadius * 0.6, foodCenterY - appleRadius * 0.9
            );
            leafGradient.addColorStop(0, '#2d5a1d');
            leafGradient.addColorStop(0.5, '#4caf50');
            leafGradient.addColorStop(1, '#2d5a1d');
            ctx.fillStyle = leafGradient;
            ctx.beginPath();
            ctx.moveTo(foodCenterX + 2, foodCenterY - appleRadius * 1.05);
            ctx.quadraticCurveTo(
                foodCenterX + appleRadius * 0.5, foodCenterY - appleRadius * 1.3,
                foodCenterX + appleRadius * 0.55, foodCenterY - appleRadius * 0.95
            );
            ctx.quadraticCurveTo(
                foodCenterX + appleRadius * 0.35, foodCenterY - appleRadius * 0.9,
                foodCenterX + 2, foodCenterY - appleRadius * 1.05
            );
            ctx.fill();

            // Leaf vein
            ctx.strokeStyle = 'rgba(0, 80, 0, 0.4)';
            ctx.lineWidth = 0.5;
            ctx.beginPath();
            ctx.moveTo(foodCenterX + 4, foodCenterY - appleRadius * 1.02);
            ctx.lineTo(foodCenterX + appleRadius * 0.4, foodCenterY - appleRadius * 1.0);
            ctx.stroke();

            return;
        }

        // Simple apple for non-realistic apple themes
        if (COLORS.foodStyle === 'apple') {
            ctx.fillStyle = 'rgba(0, 0, 0, 0.2)';
            ctx.beginPath();
            ctx.ellipse(foodCenterX + 2, foodCenterY + fw * 0.4, fw * 0.35, fw * 0.15, 0, 0, Math.PI * 2);
            ctx.fill();

            const appleGradient = ctx.createRadialGradient(
                foodCenterX - cellSize * 0.1, foodCenterY - cellSize * 0.1, 0,
                foodCenterX, foodCenterY, cellSize * 0.45
            );
            appleGradient.addColorStop(0, COLORS.FOOD_HIGHLIGHT);
            appleGradient.addColorStop(0.6, COLORS.FOOD);
            appleGradient.addColorStop(1, COLORS.FOOD_INNER);
            ctx.fillStyle = appleGradient;
            ctx.beginPath();
            ctx.arc(foodCenterX, foodCenterY, fw * 0.42, 0, Math.PI * 2);
            ctx.fill();

            ctx.strokeStyle = '#5d4037';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(foodCenterX, foodCenterY - fw * 0.35);
            ctx.quadraticCurveTo(foodCenterX + 3, foodCenterY - fw * 0.5, foodCenterX + 2, foodCenterY - fw * 0.55);
            ctx.stroke();

            ctx.fillStyle = '#4caf50';
            ctx.beginPath();
            ctx.ellipse(foodCenterX + 5, foodCenterY - fw * 0.45, 4, 2, Math.PI / 4, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
            ctx.beginPath();
            ctx.ellipse(foodCenterX - fw * 0.15, foodCenterY - fw * 0.15, fw * 0.12, fw * 0.08, -Math.PI / 4, 0, Math.PI * 2);
            ctx.fill();
            return;
        }

        // Neon theme - glow effect
        if (COLORS.useGlow) {
            const pulseScale = 1 + Math.sin(Date.now() / 200) * 0.15;
            const outerGlow = ctx.createRadialGradient(
                foodCenterX, foodCenterY, 0,
                foodCenterX, foodCenterY, cellSize * pulseScale
            );
            outerGlow.addColorStop(0, COLORS.FOOD_GLOW);
            outerGlow.addColorStop(0.5, COLORS.FOOD_GLOW_OUTER);
            outerGlow.addColorStop(1, 'transparent');
            ctx.fillStyle = outerGlow;
            ctx.beginPath();
            ctx.arc(foodCenterX, foodCenterY, cellSize * pulseScale, 0, Math.PI * 2);
            ctx.fill();
        }

        // Food body with gradient
        const foodGradient = ctx.createRadialGradient(
            foodCenterX - cellSize * 0.15, foodCenterY - cellSize * 0.15, 0,
            foodCenterX, foodCenterY, cellSize * 0.5
        );
        foodGradient.addColorStop(0, COLORS.FOOD_HIGHLIGHT);
        foodGradient.addColorStop(0.5, COLORS.FOOD);
        foodGradient.addColorStop(1, COLORS.FOOD_INNER);
        ctx.fillStyle = foodGradient;

        // Draw rounded food
        const fr = 4;
        ctx.beginPath();
        ctx.moveTo(fx + fr, fy);
        ctx.lineTo(fx + fw - fr, fy);
        ctx.quadraticCurveTo(fx + fw, fy, fx + fw, fy + fr);
        ctx.lineTo(fx + fw, fy + fw - fr);
        ctx.quadraticCurveTo(fx + fw, fy + fw, fx + fw - fr, fy + fw);
        ctx.lineTo(fx + fr, fy + fw);
        ctx.quadraticCurveTo(fx, fy + fw, fx, fy + fw - fr);
        ctx.lineTo(fx, fy + fr);
        ctx.quadraticCurveTo(fx, fy, fx + fr, fy);
        ctx.fill();
    }

    /**
     * Draw snake with theme-specific styling
     */
    function drawSnake() {
        // Realistic theme - draw connected snake body
        if (COLORS.realisticMode) {
            drawRealisticSnake();
            return;
        }

        // Draw snake segments (tail to head so head is on top)
        for (let i = snake.length - 1; i >= 0; i--) {
            const segment = snake[i];
            const x = segment.x * cellSize + CONFIG.CELL_PADDING;
            const y = segment.y * cellSize + CONFIG.CELL_PADDING;
            const w = cellSize - CONFIG.CELL_PADDING * 2;

            // Classic theme - simple blocks
            if (COLORS.blockStyle) {
                ctx.fillStyle = COLORS.SNAKE_BODY;
                ctx.fillRect(x, y, w, w);
                continue;
            }

            const r = i === 0 ? 5 : 3;

            if (i === 0) {
                // Draw head glow (if theme supports it)
                if (COLORS.useGlow) {
                    ctx.fillStyle = COLORS.SNAKE_HEAD_GLOW;
                    ctx.beginPath();
                    ctx.arc(x + w/2, y + w/2, w * 0.8, 0, Math.PI * 2);
                    ctx.fill();
                }

                // Head with gradient
                const headGradient = ctx.createRadialGradient(
                    x + w * 0.3, y + w * 0.3, 0,
                    x + w/2, y + w/2, w * 0.7
                );
                headGradient.addColorStop(0, COLORS.SNAKE_HEAD_HIGHLIGHT);
                headGradient.addColorStop(0.5, COLORS.SNAKE_HEAD);
                headGradient.addColorStop(1, COLORS.SNAKE_HEAD_SHADOW);
                ctx.fillStyle = headGradient;
            } else {
                // Body segments with fading gradient
                const progress = i / snake.length;
                const alpha = COLORS.snakePattern ? 1 : (1 - (progress * 0.4));
                const colorIndex = Math.min(2, Math.floor(progress * 3));
                ctx.fillStyle = COLORS.SNAKE_BODY_GRADIENT[colorIndex];
                ctx.globalAlpha = alpha;
            }

            // Draw rounded rectangle for each segment
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.lineTo(x + w - r, y);
            ctx.quadraticCurveTo(x + w, y, x + w, y + r);
            ctx.lineTo(x + w, y + w - r);
            ctx.quadraticCurveTo(x + w, y + w, x + w - r, y + w);
            ctx.lineTo(x + r, y + w);
            ctx.quadraticCurveTo(x, y + w, x, y + w - r);
            ctx.lineTo(x, y + r);
            ctx.quadraticCurveTo(x, y, x + r, y);
            ctx.fill();

            ctx.globalAlpha = 1;
        }

        // Draw eyes on head (skip for classic blocky theme)
        if (snake.length > 0 && !COLORS.blockStyle) {
            const head = snake[0];
            const hx = head.x * cellSize + cellSize / 2;
            const hy = head.y * cellSize + cellSize / 2;
            const eyeSize = cellSize * 0.12;
            const eyeOffset = cellSize * 0.2;

            ctx.fillStyle = COLORS.EYE_COLOR;

            // Position eyes based on direction
            let eye1x, eye1y, eye2x, eye2y;
            switch (direction) {
                case 'UP':
                    eye1x = hx - eyeOffset; eye1y = hy - eyeOffset;
                    eye2x = hx + eyeOffset; eye2y = hy - eyeOffset;
                    break;
                case 'DOWN':
                    eye1x = hx - eyeOffset; eye1y = hy + eyeOffset;
                    eye2x = hx + eyeOffset; eye2y = hy + eyeOffset;
                    break;
                case 'LEFT':
                    eye1x = hx - eyeOffset; eye1y = hy - eyeOffset;
                    eye2x = hx - eyeOffset; eye2y = hy + eyeOffset;
                    break;
                case 'RIGHT':
                default:
                    eye1x = hx + eyeOffset; eye1y = hy - eyeOffset;
                    eye2x = hx + eyeOffset; eye2y = hy + eyeOffset;
                    break;
            }

            ctx.beginPath();
            ctx.arc(eye1x, eye1y, eyeSize, 0, Math.PI * 2);
            ctx.arc(eye2x, eye2y, eyeSize, 0, Math.PI * 2);
            ctx.fill();

            // Add pupils for realistic theme
            if (COLORS.snakePattern) {
                ctx.fillStyle = '#000';
                ctx.beginPath();
                ctx.arc(eye1x, eye1y, eyeSize * 0.5, 0, Math.PI * 2);
                ctx.arc(eye2x, eye2y, eyeSize * 0.5, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }

    /**
     * Draw ultra-realistic snake with smooth body, proper scales, and detailed head
     */
    function drawRealisticSnake() {
        if (snake.length === 0) return;

        const bodyWidth = cellSize * 0.9;

        // Get all segment center points
        const points = snake.map(seg => ({
            x: seg.x * cellSize + cellSize / 2,
            y: seg.y * cellSize + cellSize / 2
        }));

        // Draw ground shadow
        ctx.fillStyle = 'rgba(0, 0, 0, 0.3)';
        ctx.beginPath();
        for (let i = 0; i < points.length; i++) {
            const p = points[i];
            const width = bodyWidth * (1 - i / points.length * 0.5) * 0.4;
            if (i === 0) {
                ctx.moveTo(p.x + 4, p.y + 4);
            }
            ctx.lineTo(p.x + 4, p.y + 4);
        }
        for (let i = points.length - 1; i >= 0; i--) {
            const p = points[i];
            ctx.lineTo(p.x + 4, p.y + 6);
        }
        ctx.closePath();
        ctx.fill();

        // Draw smooth connected body using quadratic curves
        if (points.length > 1) {
            // Create body outline path
            const leftEdge = [];
            const rightEdge = [];

            for (let i = 0; i < points.length; i++) {
                const curr = points[i];
                const next = points[i + 1] || points[i];
                const prev = points[i - 1] || points[i];

                // Calculate perpendicular direction
                let dx = next.x - prev.x;
                let dy = next.y - prev.y;
                const len = Math.sqrt(dx * dx + dy * dy) || 1;
                dx /= len;
                dy /= len;

                // Perpendicular vector
                const px = -dy;
                const py = dx;

                // Width tapers towards tail
                const taperFactor = i === 0 ? 0.7 : (1 - i / points.length * 0.6);
                const width = bodyWidth * taperFactor / 2;

                leftEdge.push({ x: curr.x + px * width, y: curr.y + py * width });
                rightEdge.push({ x: curr.x - px * width, y: curr.y - py * width });
            }

            // Draw main body with gradient
            ctx.beginPath();
            ctx.moveTo(leftEdge[0].x, leftEdge[0].y);

            // Left side (smooth curve)
            for (let i = 1; i < leftEdge.length; i++) {
                const prev = leftEdge[i - 1];
                const curr = leftEdge[i];
                const midX = (prev.x + curr.x) / 2;
                const midY = (prev.y + curr.y) / 2;
                ctx.quadraticCurveTo(prev.x, prev.y, midX, midY);
            }
            ctx.lineTo(leftEdge[leftEdge.length - 1].x, leftEdge[leftEdge.length - 1].y);

            // Tail tip
            const tailTip = points[points.length - 1];
            const tailDir = points.length > 1 ? {
                x: points[points.length - 1].x - points[points.length - 2].x,
                y: points[points.length - 1].y - points[points.length - 2].y
            } : { x: 1, y: 0 };
            const tailLen = Math.sqrt(tailDir.x * tailDir.x + tailDir.y * tailDir.y) || 1;
            ctx.lineTo(tailTip.x + tailDir.x / tailLen * cellSize * 0.3, tailTip.y + tailDir.y / tailLen * cellSize * 0.3);

            // Right side (reverse)
            for (let i = rightEdge.length - 1; i >= 0; i--) {
                const next = rightEdge[i + 1] || rightEdge[i];
                const curr = rightEdge[i];
                const midX = (next.x + curr.x) / 2;
                const midY = (next.y + curr.y) / 2;
                ctx.quadraticCurveTo(next.x, next.y, midX, midY);
            }
            ctx.closePath();

            // Main body gradient - python/grass snake pattern
            const bodyGrad = ctx.createLinearGradient(
                points[0].x - bodyWidth, points[0].y,
                points[0].x + bodyWidth, points[0].y
            );
            bodyGrad.addColorStop(0, '#1a2810');
            bodyGrad.addColorStop(0.15, '#2a3820');
            bodyGrad.addColorStop(0.3, '#3a4830');
            bodyGrad.addColorStop(0.5, '#4a5840');
            bodyGrad.addColorStop(0.7, '#3a4830');
            bodyGrad.addColorStop(0.85, '#2a3820');
            bodyGrad.addColorStop(1, '#1a2810');
            ctx.fillStyle = bodyGrad;
            ctx.fill();

            // Add specular highlight along spine
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                const prev = points[i - 1];
                const curr = points[i];
                ctx.quadraticCurveTo(prev.x, prev.y, (prev.x + curr.x) / 2, (prev.y + curr.y) / 2);
            }
            ctx.strokeStyle = 'rgba(120, 140, 100, 0.4)';
            ctx.lineWidth = bodyWidth * 0.15;
            ctx.lineCap = 'round';
            ctx.stroke();

            // Draw scale pattern
            for (let i = 1; i < points.length; i++) {
                const curr = points[i];
                const prev = points[i - 1];
                const dx = curr.x - prev.x;
                const dy = curr.y - prev.y;
                const angle = Math.atan2(dy, dx);
                const width = bodyWidth * (1 - i / points.length * 0.5);

                // Diamond scale pattern
                if (i % 2 === 1) {
                    ctx.save();
                    ctx.translate(curr.x, curr.y);
                    ctx.rotate(angle);

                    // Dark diamond pattern (like a python)
                    ctx.fillStyle = 'rgba(20, 30, 15, 0.25)';
                    ctx.beginPath();
                    ctx.moveTo(0, -width * 0.3);
                    ctx.lineTo(width * 0.25, 0);
                    ctx.lineTo(0, width * 0.3);
                    ctx.lineTo(-width * 0.25, 0);
                    ctx.closePath();
                    ctx.fill();

                    // Lighter scale edges
                    ctx.strokeStyle = 'rgba(80, 100, 60, 0.2)';
                    ctx.lineWidth = 1;
                    ctx.stroke();

                    ctx.restore();
                }

                // Side scales
                const perpX = -dy / Math.sqrt(dx * dx + dy * dy);
                const perpY = dx / Math.sqrt(dx * dx + dy * dy);

                ctx.fillStyle = 'rgba(0, 0, 0, 0.1)';
                for (let side = -1; side <= 1; side += 2) {
                    const sx = curr.x + perpX * width * 0.35 * side;
                    const sy = curr.y + perpY * width * 0.35 * side;
                    ctx.beginPath();
                    ctx.ellipse(sx, sy, width * 0.08, width * 0.12, angle, 0, Math.PI * 2);
                    ctx.fill();
                }
            }

            // Belly scales (lighter underneath)
            ctx.strokeStyle = 'rgba(180, 190, 150, 0.15)';
            ctx.lineWidth = bodyWidth * 0.4;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            for (let i = 1; i < points.length; i++) {
                const prev = points[i - 1];
                const curr = points[i];
                ctx.quadraticCurveTo(prev.x, prev.y, (prev.x + curr.x) / 2, (prev.y + curr.y) / 2);
            }
            ctx.stroke();
        }

        // Draw detailed head
        const head = points[0];
        const neck = points[1] || points[0];
        let headAngle = Math.atan2(head.y - neck.y, head.x - neck.x);

        // Override with direction for responsiveness
        switch (direction) {
            case 'UP': headAngle = -Math.PI / 2; break;
            case 'DOWN': headAngle = Math.PI / 2; break;
            case 'LEFT': headAngle = Math.PI; break;
            case 'RIGHT': headAngle = 0; break;
        }

        const headLength = bodyWidth * 0.9;
        const headWidth = bodyWidth * 0.5;

        ctx.save();
        ctx.translate(head.x, head.y);
        ctx.rotate(headAngle);

        // Head shadow
        ctx.fillStyle = 'rgba(0, 0, 0, 0.25)';
        ctx.beginPath();
        ctx.ellipse(headLength * 0.3 + 3, 3, headLength * 0.7, headWidth * 0.6, 0, 0, Math.PI * 2);
        ctx.fill();

        // Main head shape - triangular snake head
        const headGrad = ctx.createRadialGradient(
            headLength * 0.2, -headWidth * 0.2, 0,
            headLength * 0.3, 0, headLength
        );
        headGrad.addColorStop(0, '#5a6840');
        headGrad.addColorStop(0.3, '#4a5835');
        headGrad.addColorStop(0.6, '#3a4828');
        headGrad.addColorStop(1, '#2a3820');
        ctx.fillStyle = headGrad;

        ctx.beginPath();
        ctx.moveTo(-headLength * 0.2, 0);
        ctx.bezierCurveTo(
            -headLength * 0.1, -headWidth * 0.8,
            headLength * 0.4, -headWidth * 0.6,
            headLength * 0.9, -headWidth * 0.15
        );
        ctx.bezierCurveTo(
            headLength * 1.05, 0,
            headLength * 1.05, 0,
            headLength * 0.9, headWidth * 0.15
        );
        ctx.bezierCurveTo(
            headLength * 0.4, headWidth * 0.6,
            -headLength * 0.1, headWidth * 0.8,
            -headLength * 0.2, 0
        );
        ctx.fill();

        // Head top highlight
        ctx.fillStyle = 'rgba(100, 120, 80, 0.3)';
        ctx.beginPath();
        ctx.ellipse(headLength * 0.3, -headWidth * 0.15, headLength * 0.35, headWidth * 0.2, -0.2, 0, Math.PI * 2);
        ctx.fill();

        // Head scales pattern
        ctx.fillStyle = 'rgba(0, 0, 0, 0.08)';
        for (let i = 0; i < 4; i++) {
            const sx = headLength * 0.1 + i * headLength * 0.18;
            ctx.beginPath();
            ctx.ellipse(sx, 0, headLength * 0.1, headWidth * 0.25, 0, 0, Math.PI * 2);
            ctx.fill();
        }

        // Brow ridges
        ctx.fillStyle = '#2a3520';
        ctx.beginPath();
        ctx.ellipse(headLength * 0.15, -headWidth * 0.35, headLength * 0.15, headWidth * 0.12, -0.3, 0, Math.PI * 2);
        ctx.ellipse(headLength * 0.15, headWidth * 0.35, headLength * 0.15, headWidth * 0.12, 0.3, 0, Math.PI * 2);
        ctx.fill();

        // Eyes - realistic reptile eyes
        const eyeX = headLength * 0.22;
        const eyeY = headWidth * 0.32;
        const eyeW = headWidth * 0.28;
        const eyeH = headWidth * 0.35;

        // Eye socket
        ctx.fillStyle = '#1a2515';
        ctx.beginPath();
        ctx.ellipse(eyeX, -eyeY, eyeW * 1.2, eyeH * 1.1, 0, 0, Math.PI * 2);
        ctx.ellipse(eyeX, eyeY, eyeW * 1.2, eyeH * 1.1, 0, 0, Math.PI * 2);
        ctx.fill();

        // Eyeball with gradient
        const eyeGrad = ctx.createRadialGradient(
            eyeX - eyeW * 0.2, -eyeY - eyeH * 0.2, 0,
            eyeX, -eyeY, eyeW
        );
        eyeGrad.addColorStop(0, '#ffe066');
        eyeGrad.addColorStop(0.4, '#e6b800');
        eyeGrad.addColorStop(0.7, '#cc9900');
        eyeGrad.addColorStop(1, '#997700');
        ctx.fillStyle = eyeGrad;
        ctx.beginPath();
        ctx.ellipse(eyeX, -eyeY, eyeW, eyeH, 0, 0, Math.PI * 2);
        ctx.ellipse(eyeX, eyeY, eyeW, eyeH, 0, 0, Math.PI * 2);
        ctx.fill();

        // Vertical slit pupil
        ctx.fillStyle = '#000';
        ctx.beginPath();
        ctx.ellipse(eyeX + eyeW * 0.1, -eyeY, eyeW * 0.15, eyeH * 0.8, 0, 0, Math.PI * 2);
        ctx.ellipse(eyeX + eyeW * 0.1, eyeY, eyeW * 0.15, eyeH * 0.8, 0, 0, Math.PI * 2);
        ctx.fill();

        // Eye shine
        ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
        ctx.beginPath();
        ctx.ellipse(eyeX - eyeW * 0.25, -eyeY - eyeH * 0.25, eyeW * 0.15, eyeH * 0.12, -0.5, 0, Math.PI * 2);
        ctx.ellipse(eyeX - eyeW * 0.25, eyeY - eyeH * 0.25, eyeW * 0.15, eyeH * 0.12, 0.5, 0, Math.PI * 2);
        ctx.fill();

        // Nostrils
        ctx.fillStyle = '#0a1008';
        ctx.beginPath();
        ctx.ellipse(headLength * 0.75, -headWidth * 0.08, 2.5, 2, 0.3, 0, Math.PI * 2);
        ctx.ellipse(headLength * 0.75, headWidth * 0.08, 2.5, 2, -0.3, 0, Math.PI * 2);
        ctx.fill();

        // Heat pits (like a pit viper)
        ctx.fillStyle = '#1a2010';
        ctx.beginPath();
        ctx.ellipse(headLength * 0.55, -headWidth * 0.25, 3, 2, 0, 0, Math.PI * 2);
        ctx.ellipse(headLength * 0.55, headWidth * 0.25, 3, 2, 0, 0, Math.PI * 2);
        ctx.fill();

        // Forked tongue - flickering
        const tonguePhase = (Date.now() % 800) / 800;
        const tongueOut = tonguePhase < 0.5 ? tonguePhase * 2 : (1 - tonguePhase) * 2;
        const tongueLen = headLength * 0.5 * tongueOut;

        if (tongueLen > 2) {
            ctx.strokeStyle = '#dd4466';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
            ctx.beginPath();
            ctx.moveTo(headLength * 0.95, 0);
            ctx.lineTo(headLength * 0.95 + tongueLen * 0.6, 0);
            ctx.stroke();

            // Fork
            ctx.lineWidth = 1.5;
            ctx.beginPath();
            ctx.moveTo(headLength * 0.95 + tongueLen * 0.5, 0);
            ctx.lineTo(headLength * 0.95 + tongueLen, -headWidth * 0.12);
            ctx.moveTo(headLength * 0.95 + tongueLen * 0.5, 0);
            ctx.lineTo(headLength * 0.95 + tongueLen, headWidth * 0.12);
            ctx.stroke();
        }

        // Jaw line
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.15)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(headLength * 0.0, -headWidth * 0.3);
        ctx.quadraticCurveTo(headLength * 0.5, -headWidth * 0.35, headLength * 0.85, -headWidth * 0.1);
        ctx.moveTo(headLength * 0.0, headWidth * 0.3);
        ctx.quadraticCurveTo(headLength * 0.5, headWidth * 0.35, headLength * 0.85, headWidth * 0.1);
        ctx.stroke();

        ctx.restore();

        // Draw tail tip (pointed end)
        if (snake.length > 1) {
            const tail = snake[snake.length - 1];
            const prevTail = snake[snake.length - 2];
            const tx = tail.x * cellSize + cellSize / 2;
            const ty = tail.y * cellSize + cellSize / 2;
            const tailAngle = Math.atan2(
                tail.y * cellSize - prevTail.y * cellSize,
                tail.x * cellSize - prevTail.x * cellSize
            );

            ctx.save();
            ctx.translate(tx, ty);
            ctx.rotate(tailAngle);

            const tailGradient = ctx.createLinearGradient(0, -bodyWidth * 0.15, 0, bodyWidth * 0.15);
            tailGradient.addColorStop(0, '#2a3520');
            tailGradient.addColorStop(0.5, '#4a5a32');
            tailGradient.addColorStop(1, '#2a3520');
            ctx.fillStyle = tailGradient;

            ctx.beginPath();
            ctx.moveTo(-cellSize * 0.3, -bodyWidth * 0.15);
            ctx.quadraticCurveTo(cellSize * 0.2, 0, -cellSize * 0.3, bodyWidth * 0.15);
            ctx.lineTo(cellSize * 0.3, 0);
            ctx.closePath();
            ctx.fill();

            ctx.restore();
        }
    }

    /**
     * Update score display
     */
    function updateScoreDisplay() {
        ui.currentScore.textContent = score;
        ui.bestScore.textContent = SnakeStorage.getBestScore();
    }

    /**
     * Load and display leaderboard data
     */
    async function loadLeaderboardData(range = 'today') {
        try {
            const result = await SnakeAPI.getLeaderboards(range);

            if (result && result.data) {
                const data = result.data;

                // Update header scores
                if (data.family_today_top && data.family_today_top.length > 0) {
                    ui.familyBest.textContent = data.family_today_top[0].score;
                }
                if (data.global_today_top && data.global_today_top.length > 0) {
                    ui.globalBest.textContent = data.global_today_top[0].score;
                }

                // Update leaderboard lists
                const familyList = range === 'today' ? data.family_today_top : data.family_week_top;
                const globalList = range === 'today' ? data.global_today_top : data.global_week_top;

                renderLeaderboardList('family-leaderboard', familyList || []);
                renderLeaderboardList('global-leaderboard', globalList || []);
            }
        } catch (e) {
            console.error('Error loading leaderboards:', e);
        }
    }

    /**
     * Render a leaderboard list
     */
    function renderLeaderboardList(elementId, entries) {
        const list = document.getElementById(elementId);
        if (!list) return;

        if (!entries || entries.length === 0) {
            list.innerHTML = '<li class="empty">No scores yet</li>';
            return;
        }

        const userId = window.SNAKE_CONFIG?.userId;

        list.innerHTML = entries.map((entry, i) => {
            const isYou = entry.user_id === userId;
            return `
                <li class="${isYou ? 'you' : ''}">
                    <span class="rank">${i + 1}.</span>
                    <span class="name">${escapeHtml(entry.display_name || 'Player')}</span>
                    <span class="score">${entry.score}</span>
                </li>
            `;
        }).join('');
    }

    /**
     * Show leaderboards overlay
     */
    function showLeaderboards() {
        ui.gameoverScreen.classList.add('hidden');
        ui.leaderboardScreen.classList.remove('hidden');
        loadLeaderboardData(document.querySelector('.tab-btn.active')?.dataset.tab || 'today');
    }

    /**
     * Hide leaderboards overlay
     */
    function hideLeaderboards() {
        ui.leaderboardScreen.classList.add('hidden');
        ui.gameoverScreen.classList.remove('hidden');
    }

    /**
     * Hide all overlay screens
     */
    function hideAllOverlays() {
        ui.startScreen.classList.add('hidden');
        ui.pauseScreen.classList.add('hidden');
        ui.gameoverScreen.classList.add('hidden');
        ui.leaderboardScreen.classList.add('hidden');
    }

    /**
     * Update sync indicator
     */
    function updateSyncIndicator(status) {
        ui.syncIndicator.className = 'sync-indicator ' + status;
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Debounce helper
     */
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API (for debugging)
    return {
        getState: () => ({ gameState, score, snake: snake.length }),
        restart: startGame
    };
})();
