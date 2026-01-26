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

    // Tile symbols (procedural drawing, no images needed)
    var SYMBOLS = [
        // Dots (circles) 1-9
        { type: 'dots', value: 1 },
        { type: 'dots', value: 2 },
        { type: 'dots', value: 3 },
        { type: 'dots', value: 4 },
        { type: 'dots', value: 5 },
        { type: 'dots', value: 6 },
        { type: 'dots', value: 7 },
        { type: 'dots', value: 8 },
        { type: 'dots', value: 9 },
        // Bamboo (lines) 1-9
        { type: 'bamboo', value: 1 },
        { type: 'bamboo', value: 2 },
        { type: 'bamboo', value: 3 },
        { type: 'bamboo', value: 4 },
        { type: 'bamboo', value: 5 },
        { type: 'bamboo', value: 6 },
        { type: 'bamboo', value: 7 },
        { type: 'bamboo', value: 8 },
        { type: 'bamboo', value: 9 },
        // Characters (numbers) 1-9
        { type: 'character', value: 1 },
        { type: 'character', value: 2 },
        { type: 'character', value: 3 },
        { type: 'character', value: 4 },
        { type: 'character', value: 5 },
        { type: 'character', value: 6 },
        { type: 'character', value: 7 },
        { type: 'character', value: 8 },
        { type: 'character', value: 9 },
        // Winds
        { type: 'wind', value: 'E' },
        { type: 'wind', value: 'S' },
        { type: 'wind', value: 'W' },
        { type: 'wind', value: 'N' },
        // Dragons
        { type: 'dragon', value: 'R' }, // Red
        { type: 'dragon', value: 'G' }, // Green
        { type: 'dragon', value: 'W' }  // White
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
