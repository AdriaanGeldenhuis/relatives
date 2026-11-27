'use strict';

(() => {
    const modes = document.getElementById('gamesModes');
    const modeButtons = modes.querySelectorAll('button');
    const cards = Array.from(document.querySelectorAll('[data-play-modes]'));
    const surprise = document.getElementById('gamesSurprise');
    const suggestion = document.getElementById('gamesSuggestion');
    const suggestionName = document.getElementById('gamesSuggestionName');
    const suggestionLink = document.getElementById('gamesSuggestionLink');
    const browseStatus = document.getElementById('gamesBrowseStatus');
    let previousPick = null;

    modes.hidden = false;
    surprise.hidden = false;

    modes.addEventListener('click', event => {
        const button = event.target.closest('[data-mode]');
        if (!button || button.getAttribute('aria-pressed') === 'true') return;
        const mode = button.dataset.mode;
        for (const control of modeButtons) control.setAttribute('aria-pressed', String(control === button));
        for (const card of cards) card.hidden = mode !== 'all' && !card.dataset.playModes.split(' ').includes(mode);
        suggestion.hidden = true;
        const visible = cards.filter(card => !card.hidden).length;
        browseStatus.textContent = `${visible} ${visible === 1 ? 'game' : 'games'} shown.`;
    });

    surprise.addEventListener('click', () => {
        const visible = cards.filter(card => !card.hidden);
        if (!visible.length) return;
        const choices = visible.length > 1 ? visible.filter(card => card !== previousPick) : visible;
        const pick = choices[Math.floor(Math.random() * choices.length)];
        const name = pick.querySelector('h3').textContent;
        previousPick = pick;
        suggestionName.textContent = name;
        suggestionLink.textContent = `Play ${name}`;
        suggestionLink.href = pick.href;
        suggestion.hidden = false;
        browseStatus.textContent = `Try ${name}.`;
    });

    const tools = document.getElementById('gamesBoardTools');
    const scopes = tools.querySelectorAll('[data-board]');
    const game = document.getElementById('gamesRankingGame');
    const boards = document.querySelectorAll('[data-score-board]');
    const rankingStatus = document.getElementById('gamesRankingStatus');
    let scope = 'familyLeaderboard';

    function showLeaderboard(announce = false) {
        for (const button of scopes) button.setAttribute('aria-pressed', String(button.dataset.board === scope));
        for (const board of boards) {
            board.hidden = board.id !== scope;
            for (const ranks of board.querySelectorAll('[data-ranks]')) ranks.hidden = ranks.dataset.ranks !== game.value;
            if (announce && !board.hidden) {
                const title = board.querySelector('h3').textContent;
                rankingStatus.textContent = `${title} for ${game.selectedOptions[0].textContent}.`;
            }
        }
    }

    function openLinkedBoard() {
        const target = location.hash.slice(1);
        if (!Array.from(scopes).some(button => button.dataset.board === target)) return;
        scope = target;
        showLeaderboard();
        document.getElementById(scope).scrollIntoView({ block: 'start' });
    }

    tools.hidden = false;
    showLeaderboard();
    openLinkedBoard();
    window.addEventListener('hashchange', openLinkedBoard);

    for (const button of scopes) {
        button.addEventListener('click', () => {
            scope = button.dataset.board;
            showLeaderboard(true);
        });
    }
    game.addEventListener('change', () => showLeaderboard(true));
})();
