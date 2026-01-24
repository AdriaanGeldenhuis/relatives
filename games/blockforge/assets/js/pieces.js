/* =============================================
   BLOCKFORGE - Piece Definitions & Bag System
   ============================================= */

var BlockPieces = (function() {
    'use strict';

    // Tetromino shapes (standard 7 pieces)
    // Each rotation is a 2D array
    var SHAPES = {
        I: {
            rotations: [
                [[0,0,0,0],[1,1,1,1],[0,0,0,0],[0,0,0,0]],
                [[0,0,1,0],[0,0,1,0],[0,0,1,0],[0,0,1,0]],
                [[0,0,0,0],[0,0,0,0],[1,1,1,1],[0,0,0,0]],
                [[0,1,0,0],[0,1,0,0],[0,1,0,0],[0,1,0,0]]
            ],
            color: '#00f5ff',
            glowColor: 'rgba(0, 245, 255, 0.6)',
            shadowColor: 'rgba(0, 245, 255, 0.2)',
            name: 'I'
        },
        O: {
            rotations: [
                [[1,1],[1,1]],
                [[1,1],[1,1]],
                [[1,1],[1,1]],
                [[1,1],[1,1]]
            ],
            color: '#ffee00',
            glowColor: 'rgba(255, 238, 0, 0.6)',
            shadowColor: 'rgba(255, 238, 0, 0.2)',
            name: 'O'
        },
        T: {
            rotations: [
                [[0,1,0],[1,1,1],[0,0,0]],
                [[0,1,0],[0,1,1],[0,1,0]],
                [[0,0,0],[1,1,1],[0,1,0]],
                [[0,1,0],[1,1,0],[0,1,0]]
            ],
            color: '#b44aff',
            glowColor: 'rgba(180, 74, 255, 0.6)',
            shadowColor: 'rgba(180, 74, 255, 0.2)',
            name: 'T'
        },
        S: {
            rotations: [
                [[0,1,1],[1,1,0],[0,0,0]],
                [[0,1,0],[0,1,1],[0,0,1]],
                [[0,0,0],[0,1,1],[1,1,0]],
                [[1,0,0],[1,1,0],[0,1,0]]
            ],
            color: '#00ff88',
            glowColor: 'rgba(0, 255, 136, 0.6)',
            shadowColor: 'rgba(0, 255, 136, 0.2)',
            name: 'S'
        },
        Z: {
            rotations: [
                [[1,1,0],[0,1,1],[0,0,0]],
                [[0,0,1],[0,1,1],[0,1,0]],
                [[0,0,0],[1,1,0],[0,1,1]],
                [[0,1,0],[1,1,0],[1,0,0]]
            ],
            color: '#ff3366',
            glowColor: 'rgba(255, 51, 102, 0.6)',
            shadowColor: 'rgba(255, 51, 102, 0.2)',
            name: 'Z'
        },
        J: {
            rotations: [
                [[1,0,0],[1,1,1],[0,0,0]],
                [[0,1,1],[0,1,0],[0,1,0]],
                [[0,0,0],[1,1,1],[0,0,1]],
                [[0,1,0],[0,1,0],[1,1,0]]
            ],
            color: '#4a6aff',
            glowColor: 'rgba(74, 106, 255, 0.6)',
            shadowColor: 'rgba(74, 106, 255, 0.2)',
            name: 'J'
        },
        L: {
            rotations: [
                [[0,0,1],[1,1,1],[0,0,0]],
                [[0,1,0],[0,1,0],[0,1,1]],
                [[0,0,0],[1,1,1],[1,0,0]],
                [[1,1,0],[0,1,0],[0,1,0]]
            ],
            color: '#ff6600',
            glowColor: 'rgba(255, 102, 0, 0.6)',
            shadowColor: 'rgba(255, 102, 0, 0.2)',
            name: 'L'
        }
    };

    // Wall kick data (SRS)
    var WALL_KICKS = {
        normal: [
            [[0,0],[-1,0],[-1,1],[0,-2],[-1,-2]],
            [[0,0],[1,0],[1,-1],[0,2],[1,2]],
            [[0,0],[1,0],[1,1],[0,-2],[1,-2]],
            [[0,0],[-1,0],[-1,-1],[0,2],[-1,2]]
        ],
        I: [
            [[0,0],[-2,0],[1,0],[-2,-1],[1,2]],
            [[0,0],[2,0],[-1,0],[2,1],[-1,-2]],
            [[0,0],[-1,0],[2,0],[-1,2],[2,-1]],
            [[0,0],[1,0],[-2,0],[1,-2],[-2,1]]
        ]
    };

    var PIECE_NAMES = ['I', 'O', 'T', 'S', 'Z', 'J', 'L'];

    // Seeded RNG (mulberry32)
    function createRNG(seed) {
        var state = seed;
        return function() {
            state |= 0;
            state = state + 0x6D2B79F5 | 0;
            var t = Math.imul(state ^ (state >>> 15), 1 | state);
            t = t + Math.imul(t ^ (t >>> 7), 61 | t) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    // 7-bag randomizer
    function createBag(rng) {
        var bag = [];

        function refill() {
            var pieces = PIECE_NAMES.slice();
            for (var i = pieces.length - 1; i > 0; i--) {
                var j = Math.floor(rng() * (i + 1));
                var tmp = pieces[i];
                pieces[i] = pieces[j];
                pieces[j] = tmp;
            }
            bag = bag.concat(pieces);
        }

        function next() {
            if (bag.length < 7) {
                refill();
            }
            return bag.shift();
        }

        function peek(count) {
            while (bag.length < count + 7) {
                refill();
            }
            return bag.slice(0, count);
        }

        refill();

        return {
            next: next,
            peek: peek
        };
    }

    // Create a piece instance
    function createPiece(name) {
        var def = SHAPES[name];
        return {
            name: name,
            rotation: 0,
            x: 0,
            y: 0,
            shape: def.rotations[0],
            color: def.color,
            glowColor: def.glowColor,
            shadowColor: def.shadowColor
        };
    }

    function getShape(name, rotation) {
        return SHAPES[name].rotations[rotation % 4];
    }

    function getKicks(name, fromRot, toRot) {
        var kickTable = name === 'I' ? WALL_KICKS.I : WALL_KICKS.normal;
        return kickTable[fromRot];
    }

    function getColor(name) {
        return SHAPES[name].color;
    }

    function getGlowColor(name) {
        return SHAPES[name].glowColor;
    }

    function getShadowColor(name) {
        return SHAPES[name].shadowColor;
    }

    function hashSeed(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) {
            var chr = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + chr;
            hash |= 0;
        }
        return Math.abs(hash);
    }

    return {
        SHAPES: SHAPES,
        PIECE_NAMES: PIECE_NAMES,
        createRNG: createRNG,
        createBag: createBag,
        createPiece: createPiece,
        getShape: getShape,
        getKicks: getKicks,
        getColor: getColor,
        getGlowColor: getGlowColor,
        getShadowColor: getShadowColor,
        hashSeed: hashSeed
    };
})();
