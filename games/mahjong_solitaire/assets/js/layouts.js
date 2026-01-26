/* =============================================
   MAHJONG SOLITAIRE - Layouts
   ============================================= */

var MahjongLayouts = (function() {
    'use strict';

    // Layout: Simple (36 tiles = 18 pairs)
    // 3 layers pyramid
    function generateSimple() {
        var tiles = [];

        // Layer 0: 4x5 = 20 tiles
        for (var y = 0; y < 4; y++) {
            for (var x = 0; x < 5; x++) {
                tiles.push({ x: x, y: y, z: 0 });
            }
        }

        // Layer 1: 3x4 centered = 12 tiles
        for (var y = 0; y < 3; y++) {
            for (var x = 0; x < 4; x++) {
                tiles.push({ x: x + 0.5, y: y + 0.5, z: 1 });
            }
        }

        // Layer 2: 2x2 centered = 4 tiles
        for (var y = 0; y < 2; y++) {
            for (var x = 0; x < 2; x++) {
                tiles.push({ x: x + 1.5, y: y + 1, z: 2 });
            }
        }

        return tiles; // 36 tiles
    }

    // Layout: Medium (72 tiles = 36 pairs)
    // Wider pyramid with 4 layers
    function generateMedium() {
        var tiles = [];

        // Layer 0: 6x6 = 36 tiles
        for (var y = 0; y < 6; y++) {
            for (var x = 0; x < 6; x++) {
                tiles.push({ x: x, y: y, z: 0 });
            }
        }

        // Layer 1: 5x4 = 20 tiles
        for (var y = 0; y < 4; y++) {
            for (var x = 0; x < 5; x++) {
                tiles.push({ x: x + 0.5, y: y + 1, z: 1 });
            }
        }

        // Layer 2: 4x3 = 12 tiles
        for (var y = 0; y < 3; y++) {
            for (var x = 0; x < 4; x++) {
                tiles.push({ x: x + 1, y: y + 1.5, z: 2 });
            }
        }

        // Layer 3: 2x2 = 4 tiles
        for (var y = 0; y < 2; y++) {
            for (var x = 0; x < 2; x++) {
                tiles.push({ x: x + 2, y: y + 2, z: 3 });
            }
        }

        return tiles; // 72 tiles
    }

    // Layout: Turtle (144 tiles = 72 pairs)
    // Classic mahjong turtle/dragon layout
    function generateTurtle() {
        var tiles = [];

        // Layer 0 (base): Main body 12x8 with extensions
        // Main body
        for (var y = 0; y < 8; y++) {
            for (var x = 1; x < 13; x++) {
                // Skip corners to make oval shape
                if ((y === 0 || y === 7) && (x < 3 || x > 10)) continue;
                tiles.push({ x: x, y: y, z: 0 });
            }
        }

        // Left extension (single tile, connects to row 3.5)
        tiles.push({ x: 0, y: 3.5, z: 0 });

        // Right extension (2 tiles stacked)
        tiles.push({ x: 13, y: 3, z: 0 });
        tiles.push({ x: 13, y: 4, z: 0 });
        tiles.push({ x: 14, y: 3.5, z: 0 });

        // Layer 1: 10x6 centered
        for (var y = 1; y < 7; y++) {
            for (var x = 2; x < 12; x++) {
                tiles.push({ x: x + 0.5, y: y + 0.5, z: 1 });
            }
        }

        // Layer 2: 8x4 centered
        for (var y = 2; y < 6; y++) {
            for (var x = 3; x < 11; x++) {
                tiles.push({ x: x + 0.5, y: y + 0.5, z: 2 });
            }
        }

        // Layer 3: 6x2 centered
        for (var y = 3; y < 5; y++) {
            for (var x = 4; x < 10; x++) {
                tiles.push({ x: x + 0.5, y: y + 0.5, z: 3 });
            }
        }

        // Layer 4: single top tile
        tiles.push({ x: 7, y: 4, z: 4 });

        return tiles;
    }

    // Simplified Turtle for exactly 144 tiles
    function generateTurtleSimplified() {
        var tiles = [];

        // Layer 0: 12x6 = 72 tiles
        for (var y = 0; y < 6; y++) {
            for (var x = 0; x < 12; x++) {
                tiles.push({ x: x, y: y, z: 0 });
            }
        }

        // Layer 1: 10x4 = 40 tiles
        for (var y = 0; y < 4; y++) {
            for (var x = 0; x < 10; x++) {
                tiles.push({ x: x + 1, y: y + 1, z: 1 });
            }
        }

        // Layer 2: 8x2 = 16 tiles
        for (var y = 0; y < 2; y++) {
            for (var x = 0; x < 8; x++) {
                tiles.push({ x: x + 2, y: y + 2, z: 2 });
            }
        }

        // Layer 3: 6x2 = 12 tiles
        for (var y = 0; y < 2; y++) {
            for (var x = 0; x < 6; x++) {
                tiles.push({ x: x + 3, y: y + 2, z: 3 });
            }
        }

        // Layer 4: 2x2 = 4 tiles
        for (var y = 0; y < 2; y++) {
            for (var x = 0; x < 2; x++) {
                tiles.push({ x: x + 5, y: y + 2, z: 4 });
            }
        }

        return tiles; // 144 tiles
    }

    // Tile symbols - Modern icons with colors
    var SYMBOLS = [
        // Shapes (Red)
        { type: 'shape', value: 'circle', color: '#ef4444' },
        { type: 'shape', value: 'square', color: '#ef4444' },
        { type: 'shape', value: 'triangle', color: '#ef4444' },
        { type: 'shape', value: 'diamond', color: '#ef4444' },
        { type: 'shape', value: 'star', color: '#ef4444' },
        { type: 'shape', value: 'heart', color: '#ef4444' },
        { type: 'shape', value: 'hexagon', color: '#ef4444' },
        { type: 'shape', value: 'cross', color: '#ef4444' },
        { type: 'shape', value: 'crescent', color: '#ef4444' },
        // Nature (Green)
        { type: 'nature', value: 'sun', color: '#f59e0b' },
        { type: 'nature', value: 'moon', color: '#8b5cf6' },
        { type: 'nature', value: 'leaf', color: '#22c55e' },
        { type: 'nature', value: 'flower', color: '#ec4899' },
        { type: 'nature', value: 'tree', color: '#22c55e' },
        { type: 'nature', value: 'cloud', color: '#60a5fa' },
        { type: 'nature', value: 'drop', color: '#3b82f6' },
        { type: 'nature', value: 'flame', color: '#f97316' },
        { type: 'nature', value: 'snowflake', color: '#67e8f9' },
        // Objects (Blue/Purple)
        { type: 'object', value: 'crown', color: '#eab308' },
        { type: 'object', value: 'bell', color: '#fbbf24' },
        { type: 'object', value: 'gem', color: '#a855f7' },
        { type: 'object', value: 'key', color: '#78716c' },
        { type: 'object', value: 'bolt', color: '#facc15' },
        { type: 'object', value: 'apple', color: '#dc2626' },
        { type: 'object', value: 'cherry', color: '#e11d48' },
        { type: 'object', value: 'grape', color: '#7c3aed' },
        { type: 'object', value: 'lemon', color: '#fde047' },
        // Symbols (Various)
        { type: 'symbol', value: 'plus', color: '#14b8a6' },
        { type: 'symbol', value: 'minus', color: '#f43f5e' },
        { type: 'symbol', value: 'multiply', color: '#8b5cf6' },
        { type: 'symbol', value: 'spiral', color: '#06b6d4' },
        { type: 'symbol', value: 'wave', color: '#0ea5e9' },
        { type: 'symbol', value: 'infinity', color: '#6366f1' },
        { type: 'symbol', value: 'target', color: '#ef4444' },
        { type: 'symbol', value: 'eye', color: '#3b82f6' }
    ];

    // Easy mode symbols - simple, distinct, colorful (only 6)
    var EASY_SYMBOLS = [
        { type: 'shape', value: 'circle', color: '#ef4444' },
        { type: 'shape', value: 'square', color: '#3b82f6' },
        { type: 'shape', value: 'triangle', color: '#22c55e' },
        { type: 'shape', value: 'star', color: '#f59e0b' },
        { type: 'shape', value: 'heart', color: '#ec4899' },
        { type: 'shape', value: 'diamond', color: '#8b5cf6' }
    ];

    // Medium mode symbols - nature & objects (12 symbols)
    var MEDIUM_SYMBOLS = [
        { type: 'nature', value: 'sun', color: '#f59e0b' },
        { type: 'nature', value: 'moon', color: '#8b5cf6' },
        { type: 'nature', value: 'leaf', color: '#22c55e' },
        { type: 'nature', value: 'flower', color: '#ec4899' },
        { type: 'nature', value: 'cloud', color: '#60a5fa' },
        { type: 'nature', value: 'drop', color: '#3b82f6' },
        { type: 'nature', value: 'flame', color: '#f97316' },
        { type: 'nature', value: 'snowflake', color: '#67e8f9' },
        { type: 'object', value: 'crown', color: '#eab308' },
        { type: 'object', value: 'bell', color: '#fbbf24' },
        { type: 'object', value: 'gem', color: '#a855f7' },
        { type: 'object', value: 'bolt', color: '#facc15' }
    ];

    // Get required number of unique symbols for tile count
    function getSymbolsForCount(tileCount, layoutName) {
        var pairsNeeded = tileCount / 2;
        var symbols = [];

        // Easy mode: use only 6 symbols, more pairs of each
        if (layoutName === 'simple') {
            var symbolIndex = 0;
            while (symbols.length < pairsNeeded) {
                symbols.push(EASY_SYMBOLS[symbolIndex % EASY_SYMBOLS.length]);
                symbolIndex++;
            }
            return symbols;
        }

        // Medium mode: use 12 nature/object symbols
        if (layoutName === 'medium') {
            var symbolIndex = 0;
            while (symbols.length < pairsNeeded) {
                symbols.push(MEDIUM_SYMBOLS[symbolIndex % MEDIUM_SYMBOLS.length]);
                symbolIndex++;
            }
            return symbols;
        }

        // Classic mode: use all variety
        var symbolIndex = 0;
        while (symbols.length < pairsNeeded) {
            symbols.push(SYMBOLS[symbolIndex % SYMBOLS.length]);
            symbolIndex++;
        }

        return symbols;
    }

    // Layouts registry with 3 levels each
    var LAYOUTS = {
        simple: {
            name: 'Easy',
            tiles: 36,
            generate: generateSimple,
            maxLevels: 3,
            levels: [
                { timeLimit: 180000, hints: Infinity, shuffles: Infinity },  // Level 1: 3 min
                { timeLimit: 150000, hints: Infinity, shuffles: Infinity },  // Level 2: 2:30
                { timeLimit: 120000, hints: Infinity, shuffles: Infinity }   // Level 3: 2 min
            ]
        },
        medium: {
            name: 'Medium',
            tiles: 72,
            generate: generateMedium,
            maxLevels: 3,
            levels: [
                { timeLimit: 240000, hints: 50, shuffles: Infinity },  // Level 1: 4 min
                { timeLimit: 210000, hints: 30, shuffles: Infinity },  // Level 2: 3:30
                { timeLimit: 180000, hints: 20, shuffles: Infinity }   // Level 3: 3 min
            ]
        },
        turtle: {
            name: 'Classic',
            tiles: 144,
            generate: generateTurtleSimplified,
            maxLevels: 3,
            levels: [
                { timeLimit: 600000, hints: 10, shuffles: 5 },   // Level 1: 10 min
                { timeLimit: 480000, hints: 5, shuffles: 3 },    // Level 2: 8 min
                { timeLimit: 360000, hints: 3, shuffles: 2 }     // Level 3: 6 min
            ]
        }
    };

    function getLayout(name, level) {
        var layout = LAYOUTS[name] || LAYOUTS.simple;
        var lvl = Math.min(Math.max(level || 1, 1), layout.maxLevels) - 1;
        var levelConfig = layout.levels[lvl];

        return {
            name: layout.name,
            tiles: layout.tiles,
            generate: layout.generate,
            maxLevels: layout.maxLevels,
            currentLevel: lvl + 1,
            hints: levelConfig.hints,
            shuffles: levelConfig.shuffles,
            timeLimit: levelConfig.timeLimit
        };
    }

    function getLayoutNames() {
        return Object.keys(LAYOUTS);
    }

    return {
        getLayout: getLayout,
        getLayoutNames: getLayoutNames,
        getSymbolsForCount: getSymbolsForCount,
        SYMBOLS: SYMBOLS
    };

})();
