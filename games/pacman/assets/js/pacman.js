/**
 * Pac-Man Game Engine
 * Family Games Hub
 */

const PACMAN = (() => {
    'use strict';

    // Tile types
    const TILE = {
        EMPTY: 0,
        WALL: 1,
        DOT: 2,
        POWER: 3,
        GHOST_HOUSE: 4,
        GHOST_DOOR: 5,
        TUNNEL: 6
    };

    // Directions
    const DIR = {
        NONE: { x: 0, y: 0 },
        UP: { x: 0, y: -1 },
        DOWN: { x: 0, y: 1 },
        LEFT: { x: -1, y: 0 },
        RIGHT: { x: 1, y: 0 }
    };

    // Game config
    const CONFIG = {
        TILE_SIZE: 28,
        GAME_SPEED: 150,
        GHOST_SPEED: 180,
        POWER_DURATION: 8000,
        DOT_SCORE: 10,
        POWER_SCORE: 50,
        GHOST_SCORE: 200,
        LIVES: 3,
        SWIPE_THRESHOLD: 30
    };

    // Ghost colors
    const GHOST_COLORS = ['#ff0000', '#ffb8ff', '#00ffff', '#ffb852'];
    const GHOST_NAMES = ['Blinky', 'Pinky', 'Inky', 'Clyde'];

    // Level definitions - all levels have complete corner blocks (no gaps)
    const LEVELS = [
        // Level 1 - Classic layout with all corners filled
        {
            speed: 150,
            ghostSpeed: 200,
            map: [
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
                [1,2,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,2,1],
                [1,3,1,1,2,1,1,1,2,1,2,1,1,1,2,1,1,3,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,2,1,2,1,1,1,1,1,2,1,2,1,1,2,1],
                [1,2,2,2,2,1,2,2,2,1,2,2,2,1,2,2,2,2,1],
                [1,1,1,1,2,1,1,1,0,1,0,1,1,1,2,1,1,1,1],
                [1,1,1,1,2,1,0,0,0,0,0,0,0,1,2,1,1,1,1],
                [1,1,1,1,2,1,0,1,1,5,1,1,0,1,2,1,1,1,1],
                [1,0,0,0,2,0,0,1,4,4,4,1,0,0,2,0,0,0,1],
                [1,1,1,1,2,1,0,1,1,1,1,1,0,1,2,1,1,1,1],
                [1,1,1,1,2,1,0,0,0,0,0,0,0,1,2,1,1,1,1],
                [1,1,1,1,2,1,0,1,1,1,1,1,0,1,2,1,1,1,1],
                [1,2,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,2,1,1,1,2,1,2,1,1,1,2,1,1,2,1],
                [1,3,2,1,2,2,2,2,2,0,2,2,2,2,2,1,2,3,1],
                [1,1,2,1,2,1,2,1,1,1,1,1,2,1,2,1,2,1,1],
                [1,2,2,2,2,1,2,2,2,1,2,2,2,1,2,2,2,2,1],
                [1,2,1,1,1,1,1,1,2,1,2,1,1,1,1,1,1,2,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1]
            ],
            pacStart: { x: 9, y: 15 },
            ghostStart: [
                { x: 8, y: 9 },
                { x: 9, y: 9 },
                { x: 10, y: 9 },
                { x: 9, y: 8 }
            ]
        },
        // Level 2 - More complex with all corners solid
        {
            speed: 130,
            ghostSpeed: 170,
            map: [
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
                [1,2,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,1,2,1,1,2,1,2,1,1,2,1,1,1,2,1],
                [1,3,1,1,1,2,1,1,2,2,2,1,1,2,1,1,1,3,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,2,1,2,1,1,2,1,1,1,1,1,2,1,1,2,1,2,1],
                [1,2,2,2,2,1,2,2,2,1,2,2,2,1,2,2,2,2,1],
                [1,1,1,1,2,1,1,1,0,1,0,1,1,1,2,1,1,1,1],
                [1,1,1,1,2,1,0,0,0,0,0,0,0,1,2,1,1,1,1],
                [1,1,1,1,2,0,0,1,1,5,1,1,0,0,2,1,1,1,1],
                [1,0,0,0,2,0,0,1,4,4,4,1,0,0,2,0,0,0,1],
                [1,1,1,1,2,0,0,1,1,1,1,1,0,0,2,1,1,1,1],
                [1,1,1,1,2,1,0,0,0,0,0,0,0,1,2,1,1,1,1],
                [1,2,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,2,1,1,1,2,1,2,1,1,1,2,1,1,2,1],
                [1,2,1,1,2,2,2,2,2,2,2,2,2,2,2,1,1,2,1],
                [1,3,2,2,2,1,2,1,1,1,1,1,2,1,2,2,2,3,1],
                [1,2,1,1,2,1,2,2,2,1,2,2,2,1,2,1,1,2,1],
                [1,2,2,2,2,2,2,1,2,2,2,1,2,2,2,2,2,2,1],
                [1,2,1,1,1,1,2,1,2,1,2,1,2,1,1,1,1,2,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1]
            ],
            pacStart: { x: 9, y: 16 },
            ghostStart: [
                { x: 8, y: 10 },
                { x: 9, y: 10 },
                { x: 10, y: 10 },
                { x: 9, y: 9 }
            ]
        },
        // Level 3 - Advanced layout, all corners sealed
        {
            speed: 110,
            ghostSpeed: 150,
            map: [
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
                [1,3,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,3,1],
                [1,2,1,1,2,1,1,1,2,1,2,1,1,1,2,1,1,2,1],
                [1,2,1,1,2,2,2,1,2,2,2,1,2,2,2,1,1,2,1],
                [1,2,2,2,2,1,2,2,2,1,2,2,2,1,2,2,2,2,1],
                [1,1,1,2,1,1,1,1,2,1,2,1,1,1,1,2,1,1,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,2,1,0,1,1,1,1,1,0,1,2,1,1,2,1],
                [1,2,2,1,2,1,0,0,0,0,0,0,0,1,2,1,2,2,1],
                [1,1,2,2,2,0,0,1,1,5,1,1,0,0,2,2,2,1,1],
                [1,0,0,0,2,0,0,1,4,4,4,1,0,0,2,0,0,0,1],
                [1,1,2,2,2,0,0,1,1,1,1,1,0,0,2,2,2,1,1],
                [1,2,2,1,2,1,0,0,0,0,0,0,0,1,2,1,2,2,1],
                [1,2,1,1,2,1,0,1,1,1,1,1,0,1,2,1,1,2,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,1,1,2,1,1,2,1,1,1,1,1,2,1,1,2,1,1,1],
                [1,2,2,2,2,1,2,2,2,1,2,2,2,1,2,2,2,2,1],
                [1,2,1,1,2,2,2,1,2,2,2,1,2,2,2,1,1,2,1],
                [1,2,1,1,2,1,1,1,2,1,2,1,1,1,2,1,1,2,1],
                [1,3,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,3,1],
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1]
            ],
            pacStart: { x: 9, y: 14 },
            ghostStart: [
                { x: 8, y: 10 },
                { x: 9, y: 10 },
                { x: 10, y: 10 },
                { x: 9, y: 9 }
            ]
        },
        // Level 4 - Expert layout, tight corridors, all corners sealed
        {
            speed: 100,
            ghostSpeed: 130,
            map: [
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
                [1,2,2,2,1,2,2,2,2,2,2,2,2,2,1,2,2,2,1],
                [1,2,1,2,1,2,1,1,2,1,2,1,1,2,1,2,1,2,1],
                [1,3,1,2,2,2,2,1,2,1,2,1,2,2,2,2,1,3,1],
                [1,2,2,2,1,1,2,2,2,2,2,2,2,1,1,2,2,2,1],
                [1,2,1,2,2,1,1,1,2,1,2,1,1,1,2,2,1,2,1],
                [1,2,1,1,2,2,2,2,2,1,2,2,2,2,2,1,1,2,1],
                [1,2,2,1,2,1,0,1,1,1,1,1,0,1,2,1,2,2,1],
                [1,1,2,2,2,1,0,0,0,0,0,0,0,1,2,2,2,1,1],
                [1,1,1,1,2,0,0,1,1,5,1,1,0,0,2,1,1,1,1],
                [1,0,0,0,2,0,0,1,4,4,4,1,0,0,2,0,0,0,1],
                [1,1,1,1,2,0,0,1,1,1,1,1,0,0,2,1,1,1,1],
                [1,1,2,2,2,1,0,0,0,0,0,0,0,1,2,2,2,1,1],
                [1,2,2,1,2,1,0,1,1,1,1,1,0,1,2,1,2,2,1],
                [1,2,1,1,2,2,2,2,2,2,2,2,2,2,2,1,1,2,1],
                [1,2,1,2,2,1,1,1,2,1,2,1,1,1,2,2,1,2,1],
                [1,2,2,2,1,1,2,2,2,2,2,2,2,1,1,2,2,2,1],
                [1,3,1,2,2,2,2,1,2,1,2,1,2,2,2,2,1,3,1],
                [1,2,1,2,1,2,1,1,2,1,2,1,1,2,1,2,1,2,1],
                [1,2,2,2,1,2,2,2,2,2,2,2,2,2,1,2,2,2,1],
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1]
            ],
            pacStart: { x: 9, y: 14 },
            ghostStart: [
                { x: 8, y: 10 },
                { x: 9, y: 10 },
                { x: 10, y: 10 },
                { x: 9, y: 9 }
            ]
        },
        // Level 5 - Master layout, all corners properly sealed
        {
            speed: 90,
            ghostSpeed: 120,
            map: [
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
                [1,3,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,3,1],
                [1,2,1,1,1,2,1,1,2,1,2,1,1,2,1,1,1,2,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,2,1,2,1,2,1,1,1,1,1,1,1,2,1,2,1,2,1],
                [1,2,1,2,2,2,2,2,2,1,2,2,2,2,2,2,1,2,1],
                [1,2,2,2,1,1,1,1,2,2,2,1,1,1,1,2,2,2,1],
                [1,1,1,2,1,2,0,1,1,1,1,1,0,2,1,2,1,1,1],
                [1,2,2,2,2,2,0,0,0,0,0,0,0,2,2,2,2,2,1],
                [1,2,1,1,1,0,0,1,1,5,1,1,0,0,1,1,1,2,1],
                [1,0,0,0,2,0,0,1,4,4,4,1,0,0,2,0,0,0,1],
                [1,2,1,1,1,0,0,1,1,1,1,1,0,0,1,1,1,2,1],
                [1,2,2,2,2,2,0,0,0,0,0,0,0,2,2,2,2,2,1],
                [1,1,1,2,1,2,0,1,1,1,1,1,0,2,1,2,1,1,1],
                [1,2,2,2,1,1,1,1,2,2,2,1,1,1,1,2,2,2,1],
                [1,2,1,2,2,2,2,2,2,1,2,2,2,2,2,2,1,2,1],
                [1,2,1,2,1,2,1,1,1,1,1,1,1,2,1,2,1,2,1],
                [1,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,2,1],
                [1,2,1,1,1,2,1,1,2,1,2,1,1,2,1,1,1,2,1],
                [1,3,2,2,2,2,2,2,2,1,2,2,2,2,2,2,2,3,1],
                [1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1]
            ],
            pacStart: { x: 9, y: 17 },
            ghostStart: [
                { x: 8, y: 10 },
                { x: 9, y: 10 },
                { x: 10, y: 10 },
                { x: 9, y: 9 }
            ]
        }
    ];

    // Game state
    let canvas, ctx;
    let currentLevel = 0;
    let score = 0;
    let lives = CONFIG.LIVES;
    let gameState = 'idle'; // idle, playing, paused, gameover, win
    let map = [];
    let dotsRemaining = 0;
    let animFrame = 0;
    let lastUpdate = 0;
    let lastGhostUpdate = 0;
    let powerTimer = null;
    let isPowered = false;
    let mouthAngle = 0;
    let mouthDir = 1;
    let ghostFlash = false;
    let flashInterval = null;
    let touchStartX = 0;
    let touchStartY = 0;

    // Entities
    let pacman = { x: 9, y: 15, dir: DIR.NONE, nextDir: DIR.NONE };
    let ghosts = [];

    function init() {
        canvas = document.getElementById('pacman-canvas');
        ctx = canvas.getContext('2d');

        setupControls();
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        drawIdleScreen();
    }

    function resizeCanvas() {
        const container = document.getElementById('game-container');
        const maxWidth = Math.min(container.clientWidth - 20, 600);
        const level = LEVELS[currentLevel];
        const cols = level.map[0].length;
        const rows = level.map.length;
        const tileSize = Math.floor(maxWidth / cols);

        CONFIG.TILE_SIZE = tileSize;
        canvas.width = cols * tileSize;
        canvas.height = rows * tileSize;

        if (gameState === 'idle') drawIdleScreen();
        else if (gameState === 'playing' || gameState === 'paused') draw();
    }

    function setupControls() {
        // Keyboard
        document.addEventListener('keydown', (e) => {
            switch (e.key) {
                case 'ArrowUp': case 'w': case 'W':
                    e.preventDefault();
                    setDirection(DIR.UP);
                    break;
                case 'ArrowDown': case 's': case 'S':
                    e.preventDefault();
                    setDirection(DIR.DOWN);
                    break;
                case 'ArrowLeft': case 'a': case 'A':
                    e.preventDefault();
                    setDirection(DIR.LEFT);
                    break;
                case 'ArrowRight': case 'd': case 'D':
                    e.preventDefault();
                    setDirection(DIR.RIGHT);
                    break;
                case ' ':
                    e.preventDefault();
                    if (gameState === 'idle' || gameState === 'gameover' || gameState === 'win') startGame();
                    else if (gameState === 'playing') pauseGame();
                    else if (gameState === 'paused') resumeGame();
                    break;
            }
        });

        // Touch
        canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;

            if (gameState === 'idle' || gameState === 'gameover' || gameState === 'win') {
                startGame();
            }
        }, { passive: false });

        canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            const touch = e.changedTouches[0];
            const dx = touch.clientX - touchStartX;
            const dy = touch.clientY - touchStartY;

            if (Math.abs(dx) < CONFIG.SWIPE_THRESHOLD && Math.abs(dy) < CONFIG.SWIPE_THRESHOLD) return;

            if (Math.abs(dx) > Math.abs(dy)) {
                setDirection(dx > 0 ? DIR.RIGHT : DIR.LEFT);
            } else {
                setDirection(dy > 0 ? DIR.DOWN : DIR.UP);
            }
        }, { passive: false });

        // Buttons
        document.getElementById('btn-start').addEventListener('click', () => {
            if (gameState === 'idle' || gameState === 'gameover' || gameState === 'win') startGame();
            else if (gameState === 'paused') resumeGame();
        });
        document.getElementById('btn-pause').addEventListener('click', () => {
            if (gameState === 'playing') pauseGame();
        });

        // D-pad
        document.getElementById('dpad-up').addEventListener('click', () => setDirection(DIR.UP));
        document.getElementById('dpad-down').addEventListener('click', () => setDirection(DIR.DOWN));
        document.getElementById('dpad-left').addEventListener('click', () => setDirection(DIR.LEFT));
        document.getElementById('dpad-right').addEventListener('click', () => setDirection(DIR.RIGHT));
    }

    function setDirection(dir) {
        if (gameState !== 'playing') return;
        pacman.nextDir = dir;
    }

    function startGame() {
        currentLevel = 0;
        score = 0;
        lives = CONFIG.LIVES;
        loadLevel(currentLevel);
        gameState = 'playing';
        updateUI();
        lastUpdate = performance.now();
        lastGhostUpdate = performance.now();
        requestAnimationFrame(gameLoop);
    }

    function loadLevel(levelIdx) {
        const level = LEVELS[levelIdx];
        map = level.map.map(row => [...row]);
        dotsRemaining = 0;

        // Count dots
        for (let y = 0; y < map.length; y++) {
            for (let x = 0; x < map[y].length; x++) {
                if (map[y][x] === TILE.DOT || map[y][x] === TILE.POWER) {
                    dotsRemaining++;
                }
            }
        }

        // Reset pacman
        pacman.x = level.pacStart.x;
        pacman.y = level.pacStart.y;
        pacman.dir = DIR.NONE;
        pacman.nextDir = DIR.NONE;

        // Reset ghosts
        ghosts = level.ghostStart.map((pos, i) => ({
            x: pos.x,
            y: pos.y,
            startX: pos.x,
            startY: pos.y,
            dir: DIR.NONE,
            color: GHOST_COLORS[i],
            name: GHOST_NAMES[i],
            scared: false,
            eaten: false,
            inHouse: true,
            releaseTimer: i * 3000
        }));

        isPowered = false;
        if (powerTimer) clearTimeout(powerTimer);
        if (flashInterval) clearInterval(flashInterval);

        resizeCanvas();
        updateUI();
    }

    function resetPositions() {
        const level = LEVELS[currentLevel];
        pacman.x = level.pacStart.x;
        pacman.y = level.pacStart.y;
        pacman.dir = DIR.NONE;
        pacman.nextDir = DIR.NONE;

        ghosts.forEach((ghost, i) => {
            ghost.x = ghost.startX;
            ghost.y = ghost.startY;
            ghost.dir = DIR.NONE;
            ghost.scared = false;
            ghost.eaten = false;
            ghost.inHouse = true;
            ghost.releaseTimer = i * 3000;
        });

        isPowered = false;
        if (powerTimer) clearTimeout(powerTimer);
        if (flashInterval) clearInterval(flashInterval);
    }

    function pauseGame() {
        gameState = 'paused';
        updateUI();
    }

    function resumeGame() {
        gameState = 'playing';
        lastUpdate = performance.now();
        lastGhostUpdate = performance.now();
        updateUI();
        requestAnimationFrame(gameLoop);
    }

    function gameLoop(timestamp) {
        if (gameState !== 'playing') return;

        // Update pacman
        if (timestamp - lastUpdate >= LEVELS[currentLevel].speed) {
            updatePacman();
            lastUpdate = timestamp;
        }

        // Update ghosts
        if (timestamp - lastGhostUpdate >= LEVELS[currentLevel].ghostSpeed) {
            updateGhosts(timestamp);
            lastGhostUpdate = timestamp;
        }

        // Animate mouth
        animFrame++;
        mouthAngle += 0.15 * mouthDir;
        if (mouthAngle > 0.8 || mouthAngle < 0.05) mouthDir *= -1;

        draw();
        checkCollisions();

        if (gameState === 'playing') {
            requestAnimationFrame(gameLoop);
        }
    }

    function canMove(x, y) {
        if (y < 0 || y >= map.length || x < 0 || x >= map[0].length) return false;
        const tile = map[y][x];
        return tile !== TILE.WALL && tile !== TILE.GHOST_HOUSE;
    }

    function canGhostMove(x, y, ghost) {
        if (y < 0 || y >= map.length || x < 0 || x >= map[0].length) return false;
        const tile = map[y][x];
        if (tile === TILE.WALL) return false;
        if (tile === TILE.GHOST_DOOR && !ghost.inHouse && !ghost.eaten) return false;
        return true;
    }

    function updatePacman() {
        // Try next direction first
        const nextX = pacman.x + pacman.nextDir.x;
        const nextY = pacman.y + pacman.nextDir.y;
        if (canMove(nextX, nextY)) {
            pacman.dir = pacman.nextDir;
        }

        // Move in current direction
        const newX = pacman.x + pacman.dir.x;
        const newY = pacman.y + pacman.dir.y;
        if (canMove(newX, newY)) {
            pacman.x = newX;
            pacman.y = newY;

            // Collect dots
            const tile = map[pacman.y][pacman.x];
            if (tile === TILE.DOT) {
                map[pacman.y][pacman.x] = TILE.EMPTY;
                score += CONFIG.DOT_SCORE;
                dotsRemaining--;
                updateUI();
            } else if (tile === TILE.POWER) {
                map[pacman.y][pacman.x] = TILE.EMPTY;
                score += CONFIG.POWER_SCORE;
                dotsRemaining--;
                activatePower();
                updateUI();
            }

            // Check level complete
            if (dotsRemaining <= 0) {
                nextLevel();
            }
        }
    }

    function updateGhosts(timestamp) {
        ghosts.forEach((ghost, idx) => {
            // Release from house
            if (ghost.inHouse) {
                ghost.releaseTimer -= LEVELS[currentLevel].ghostSpeed;
                if (ghost.releaseTimer <= 0) {
                    ghost.inHouse = false;
                    // Move to door position
                    const level = LEVELS[currentLevel];
                    for (let y = 0; y < map.length; y++) {
                        for (let x = 0; x < map[y].length; x++) {
                            if (map[y][x] === TILE.GHOST_DOOR) {
                                ghost.x = x;
                                ghost.y = y - 1;
                                ghost.dir = DIR.UP;
                                return;
                            }
                        }
                    }
                }
                return;
            }

            // AI movement
            const possibleDirs = [DIR.UP, DIR.DOWN, DIR.LEFT, DIR.RIGHT].filter(d => {
                // Don't reverse
                if (d.x === -ghost.dir.x && d.y === -ghost.dir.y && ghost.dir.x !== 0 ||
                    d.y === -ghost.dir.y && d.x === -ghost.dir.x && ghost.dir.y !== 0) {
                    if (!ghost.scared) return false;
                }
                return canGhostMove(ghost.x + d.x, ghost.y + d.y, ghost);
            });

            if (possibleDirs.length === 0) {
                ghost.dir = DIR.NONE;
                return;
            }

            if (ghost.scared || ghost.eaten) {
                // Random movement when scared, move to house when eaten
                if (ghost.eaten) {
                    // Move toward ghost house
                    const target = { x: ghost.startX, y: ghost.startY };
                    const best = possibleDirs.reduce((a, b) => {
                        const da = Math.abs(ghost.x + a.x - target.x) + Math.abs(ghost.y + a.y - target.y);
                        const db = Math.abs(ghost.x + b.x - target.x) + Math.abs(ghost.y + b.y - target.y);
                        return da < db ? a : b;
                    });
                    ghost.dir = best;
                    ghost.x += ghost.dir.x;
                    ghost.y += ghost.dir.y;

                    // Check if reached home
                    if (ghost.x === ghost.startX && ghost.y === ghost.startY) {
                        ghost.eaten = false;
                        ghost.scared = false;
                        ghost.inHouse = true;
                        ghost.releaseTimer = 2000;
                    }
                } else {
                    // Random when scared
                    ghost.dir = possibleDirs[Math.floor(Math.random() * possibleDirs.length)];
                    ghost.x += ghost.dir.x;
                    ghost.y += ghost.dir.y;
                }
            } else {
                // Chase pacman (simple: move toward pac-man)
                let targetX = pacman.x;
                let targetY = pacman.y;

                // Different ghost behaviors
                switch (idx) {
                    case 0: // Blinky - direct chase
                        break;
                    case 1: // Pinky - aim ahead of pacman
                        targetX += pacman.dir.x * 4;
                        targetY += pacman.dir.y * 4;
                        break;
                    case 2: // Inky - flank
                        targetX = pacman.x + (pacman.x - ghosts[0].x);
                        targetY = pacman.y + (pacman.y - ghosts[0].y);
                        break;
                    case 3: // Clyde - chase or scatter
                        const dist = Math.abs(ghost.x - pacman.x) + Math.abs(ghost.y - pacman.y);
                        if (dist < 8) {
                            targetX = 0;
                            targetY = map.length - 1;
                        }
                        break;
                }

                const best = possibleDirs.reduce((a, b) => {
                    const da = Math.abs(ghost.x + a.x - targetX) + Math.abs(ghost.y + a.y - targetY);
                    const db = Math.abs(ghost.x + b.x - targetX) + Math.abs(ghost.y + b.y - targetY);
                    return da < db ? a : b;
                });

                ghost.dir = best;
                ghost.x += ghost.dir.x;
                ghost.y += ghost.dir.y;
            }
        });
    }

    function checkCollisions() {
        ghosts.forEach(ghost => {
            if (ghost.inHouse) return;
            if (ghost.x === pacman.x && ghost.y === pacman.y) {
                if (ghost.scared && !ghost.eaten) {
                    // Eat ghost
                    ghost.eaten = true;
                    score += CONFIG.GHOST_SCORE;
                    updateUI();
                } else if (!ghost.eaten) {
                    // Pacman dies
                    lives--;
                    updateUI();
                    if (lives <= 0) {
                        gameOver();
                    } else {
                        resetPositions();
                    }
                }
            }
        });
    }

    function activatePower() {
        isPowered = true;
        ghostFlash = false;
        if (powerTimer) clearTimeout(powerTimer);
        if (flashInterval) clearInterval(flashInterval);

        ghosts.forEach(g => {
            if (!g.inHouse && !g.eaten) g.scared = true;
        });

        // Flash warning before power ends
        flashInterval = setInterval(() => {
            ghostFlash = !ghostFlash;
        }, 200);

        powerTimer = setTimeout(() => {
            isPowered = false;
            ghostFlash = false;
            clearInterval(flashInterval);
            ghosts.forEach(g => { g.scared = false; });
        }, CONFIG.POWER_DURATION);
    }

    function nextLevel() {
        currentLevel++;
        if (currentLevel >= LEVELS.length) {
            gameState = 'win';
            draw();
            updateUI();
            return;
        }
        loadLevel(currentLevel);
        gameState = 'playing';
        lastUpdate = performance.now();
        lastGhostUpdate = performance.now();
    }

    function gameOver() {
        gameState = 'gameover';
        if (powerTimer) clearTimeout(powerTimer);
        if (flashInterval) clearInterval(flashInterval);
        draw();
        updateUI();
    }

    // ======= DRAWING =======

    function draw() {
        ctx.fillStyle = '#0a0a2e';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        drawMap();
        drawPacman();
        drawGhosts();

        if (gameState === 'paused') drawOverlay('PAUSED', 'Tap or press SPACE to resume');
        if (gameState === 'gameover') drawOverlay('GAME OVER', `Score: ${score} | Tap to restart`);
        if (gameState === 'win') drawOverlay('YOU WIN!', `Final Score: ${score} | Tap to play again`);
    }

    function drawMap() {
        const ts = CONFIG.TILE_SIZE;

        for (let y = 0; y < map.length; y++) {
            for (let x = 0; x < map[y].length; x++) {
                const tile = map[y][x];
                const px = x * ts;
                const py = y * ts;

                if (tile === TILE.WALL) {
                    // Wall block with subtle gradient
                    const gradient = ctx.createLinearGradient(px, py, px + ts, py + ts);
                    gradient.addColorStop(0, '#1a3a6b');
                    gradient.addColorStop(1, '#0d2247');
                    ctx.fillStyle = gradient;
                    ctx.fillRect(px + 1, py + 1, ts - 2, ts - 2);

                    // Border glow
                    ctx.strokeStyle = '#2a5aab';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(px + 1.5, py + 1.5, ts - 3, ts - 3);
                } else if (tile === TILE.DOT) {
                    ctx.beginPath();
                    ctx.arc(px + ts / 2, py + ts / 2, ts * 0.1, 0, Math.PI * 2);
                    ctx.fillStyle = '#ffdd00';
                    ctx.fill();
                } else if (tile === TILE.POWER) {
                    const pulse = Math.sin(animFrame * 0.1) * 0.3 + 1;
                    ctx.beginPath();
                    ctx.arc(px + ts / 2, py + ts / 2, ts * 0.25 * pulse, 0, Math.PI * 2);
                    ctx.fillStyle = '#ff69b4';
                    ctx.fill();
                    // Glow
                    ctx.shadowColor = '#ff69b4';
                    ctx.shadowBlur = 8;
                    ctx.fill();
                    ctx.shadowBlur = 0;
                } else if (tile === TILE.GHOST_DOOR) {
                    ctx.fillStyle = '#ffb8ff';
                    ctx.fillRect(px + 2, py + ts / 2 - 2, ts - 4, 4);
                }
            }
        }
    }

    function drawPacman() {
        const ts = CONFIG.TILE_SIZE;
        const px = pacman.x * ts + ts / 2;
        const py = pacman.y * ts + ts / 2;
        const radius = ts * 0.4;

        // Direction angle
        let angle = 0;
        if (pacman.dir === DIR.RIGHT) angle = 0;
        else if (pacman.dir === DIR.DOWN) angle = Math.PI / 2;
        else if (pacman.dir === DIR.LEFT) angle = Math.PI;
        else if (pacman.dir === DIR.UP) angle = -Math.PI / 2;

        // Glow effect
        ctx.shadowColor = '#00ffff';
        ctx.shadowBlur = 12;

        ctx.beginPath();
        ctx.arc(px, py, radius, angle + mouthAngle, angle + Math.PI * 2 - mouthAngle);
        ctx.lineTo(px, py);
        ctx.closePath();

        const gradient = ctx.createRadialGradient(px, py, 0, px, py, radius);
        gradient.addColorStop(0, '#00ffff');
        gradient.addColorStop(1, '#0088aa');
        ctx.fillStyle = gradient;
        ctx.fill();

        ctx.shadowBlur = 0;
    }

    function drawGhosts() {
        const ts = CONFIG.TILE_SIZE;

        ghosts.forEach((ghost, idx) => {
            const px = ghost.x * ts + ts / 2;
            const py = ghost.y * ts + ts / 2;
            const radius = ts * 0.4;

            let color = ghost.color;
            if (ghost.eaten) {
                // Only draw eyes
                drawGhostEyes(px, py, radius, ghost);
                return;
            }
            if (ghost.scared) {
                color = ghostFlash ? '#fff' : '#2222ff';
            }

            // Body
            ctx.beginPath();
            ctx.arc(px, py - radius * 0.2, radius, Math.PI, 0, false);
            // Wavy bottom
            const waveOffset = animFrame % 2 === 0 ? 0 : ts * 0.05;
            ctx.lineTo(px + radius, py + radius * 0.8);
            for (let i = 0; i < 4; i++) {
                const wx = px + radius - (i + 1) * (radius * 2 / 4);
                const wy = py + radius * 0.8 + (i % 2 === 0 ? waveOffset : -waveOffset);
                ctx.lineTo(wx, wy);
            }
            ctx.closePath();
            ctx.fillStyle = color;
            ctx.fill();

            // Eyes
            if (!ghost.scared) {
                drawGhostEyes(px, py, radius, ghost);
            } else {
                // Scared face
                ctx.fillStyle = '#fff';
                ctx.beginPath();
                ctx.arc(px - radius * 0.3, py - radius * 0.3, radius * 0.15, 0, Math.PI * 2);
                ctx.arc(px + radius * 0.3, py - radius * 0.3, radius * 0.15, 0, Math.PI * 2);
                ctx.fill();
            }
        });
    }

    function drawGhostEyes(px, py, radius, ghost) {
        const eyeR = radius * 0.2;
        const pupilR = radius * 0.1;

        // White of eyes
        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(px - radius * 0.3, py - radius * 0.3, eyeR, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(px + radius * 0.3, py - radius * 0.3, eyeR, 0, Math.PI * 2);
        ctx.fill();

        // Pupils (look at pacman)
        const dx = pacman.x - ghost.x;
        const dy = pacman.y - ghost.y;
        const dist = Math.sqrt(dx * dx + dy * dy) || 1;
        const offsetX = (dx / dist) * pupilR * 0.8;
        const offsetY = (dy / dist) * pupilR * 0.8;

        ctx.fillStyle = '#00f';
        ctx.beginPath();
        ctx.arc(px - radius * 0.3 + offsetX, py - radius * 0.3 + offsetY, pupilR, 0, Math.PI * 2);
        ctx.fill();
        ctx.beginPath();
        ctx.arc(px + radius * 0.3 + offsetX, py - radius * 0.3 + offsetY, pupilR, 0, Math.PI * 2);
        ctx.fill();
    }

    function drawOverlay(title, subtitle) {
        ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#fff';
        ctx.font = `bold ${CONFIG.TILE_SIZE}px "Space Grotesk", sans-serif`;
        ctx.textAlign = 'center';
        ctx.fillText(title, canvas.width / 2, canvas.height / 2 - 10);

        ctx.font = `${CONFIG.TILE_SIZE * 0.5}px "Space Grotesk", sans-serif`;
        ctx.fillStyle = 'rgba(255,255,255,0.7)';
        ctx.fillText(subtitle, canvas.width / 2, canvas.height / 2 + 25);
        ctx.textAlign = 'left';
    }

    function drawIdleScreen() {
        if (!ctx) return;
        const level = LEVELS[0];
        const cols = level.map[0].length;
        const rows = level.map.length;

        ctx.fillStyle = '#0a0a2e';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Draw the level 1 map as preview
        const ts = CONFIG.TILE_SIZE;
        for (let y = 0; y < level.map.length; y++) {
            for (let x = 0; x < level.map[y].length; x++) {
                const tile = level.map[y][x];
                const px = x * ts;
                const py = y * ts;

                if (tile === TILE.WALL) {
                    ctx.fillStyle = '#1a3a6b';
                    ctx.fillRect(px + 1, py + 1, ts - 2, ts - 2);
                    ctx.strokeStyle = '#2a5aab';
                    ctx.lineWidth = 1;
                    ctx.strokeRect(px + 1.5, py + 1.5, ts - 3, ts - 3);
                } else if (tile === TILE.DOT) {
                    ctx.beginPath();
                    ctx.arc(px + ts / 2, py + ts / 2, ts * 0.1, 0, Math.PI * 2);
                    ctx.fillStyle = '#ffdd00';
                    ctx.fill();
                } else if (tile === TILE.POWER) {
                    ctx.beginPath();
                    ctx.arc(px + ts / 2, py + ts / 2, ts * 0.25, 0, Math.PI * 2);
                    ctx.fillStyle = '#ff69b4';
                    ctx.fill();
                }
            }
        }

        // Overlay
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#00ffff';
        ctx.font = `bold ${ts * 1.2}px "Space Grotesk", sans-serif`;
        ctx.textAlign = 'center';
        ctx.fillText('PAC-MAN', canvas.width / 2, canvas.height / 2 - 20);

        ctx.fillStyle = '#fff';
        ctx.font = `${ts * 0.55}px "Space Grotesk", sans-serif`;
        ctx.fillText('Tap or press SPACE to start', canvas.width / 2, canvas.height / 2 + 20);
        ctx.textAlign = 'left';
    }

    function updateUI() {
        document.getElementById('score-value').textContent = score;
        document.getElementById('level-value').textContent = currentLevel + 1;
        document.getElementById('lives-value').textContent = lives;

        const startBtn = document.getElementById('btn-start');
        const pauseBtn = document.getElementById('btn-pause');

        if (gameState === 'playing') {
            startBtn.style.display = 'none';
            pauseBtn.style.display = 'inline-flex';
        } else if (gameState === 'paused') {
            startBtn.textContent = 'Resume';
            startBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        } else {
            startBtn.textContent = gameState === 'gameover' || gameState === 'win' ? 'Play Again' : 'Start';
            startBtn.style.display = 'inline-flex';
            pauseBtn.style.display = 'none';
        }
    }

    return { init };
})();

document.addEventListener('DOMContentLoaded', PACMAN.init);
