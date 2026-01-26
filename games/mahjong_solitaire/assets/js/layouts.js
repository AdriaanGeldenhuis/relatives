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

    // Get required number of unique symbols for tile count
    function getSymbolsForCount(tileCount) {
        var pairsNeeded = tileCount / 2;
        var symbols = [];

        // Cycle through symbols, 4 of each (2 pairs per symbol in classic mahjong)
        // For simplicity, we'll use 2 of each (1 pair)
        var symbolIndex = 0;
        while (symbols.length < pairsNeeded) {
            symbols.push(SYMBOLS[symbolIndex % SYMBOLS.length]);
            symbolIndex++;
        }

        return symbols;
    }

    // Layouts registry
    var LAYOUTS = {
        simple: {
            name: 'Easy',
            tiles: 36,
            generate: generateSimple,
            hints: Infinity,
            shuffles: 3
        },
        medium: {
            name: 'Medium',
            tiles: 72,
            generate: generateMedium,
            hints: 5,
            shuffles: 2
        },
        turtle: {
            name: 'Classic',
            tiles: 144,
            generate: generateTurtleSimplified,
            hints: 3,
            shuffles: 1
        }
    };

    function getLayout(name) {
        return LAYOUTS[name] || LAYOUTS.simple;
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
