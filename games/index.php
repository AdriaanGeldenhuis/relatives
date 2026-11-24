<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/session_boot.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
require_once __DIR__ . '/../core/bootstrap.php';

$user = (new Auth($db))->getCurrentUser();
if (!$user) {
    header('Location: /login.php?session_expired=1');
    exit;
}

header('Cache-Control: private, no-store');
$userId = (int)$user['id'];
$familyId = (int)$user['family_id'];

$games = [
    'flash' => ['Flash Challenge', '⚡', 'Race the clock in a daily 30-second trivia challenge.', 'flash_challenge', 'honey', 'Daily trivia'],
    'snake' => ['Snake Classic', '🐍', 'Eat, grow and see how long you can keep going.', 'snake', 'mint', 'Arcade · Offline'],
    'neon' => ['Neon Nibbler', '💠', 'Collect sparks and dodge sentinels as you explore the maze.', 'neon_nibbler', 'sky', 'Maze chase · Offline'],
    'blockforge' => ['BlockForge', '🧱', 'Clear lines in solo play, a daily puzzle or the family board.', 'blockforge', 'rose', 'Block puzzle · Offline'],
    '30seconds' => ['30 Seconds Party', '⏱️', 'Split into teams and help your partner guess the words before time runs out.', '30_seconds_offline', 'lilac', 'Team game · Offline'],
    'memory' => ['Memory Match', '🧠', 'Match free tiles to clear the board. Choose from three layouts.', 'mahjong_solitaire', 'cream', 'Tile puzzle']
];
$scoreSources = [
    'flash' => ['flash_attempts', 'challenge_date', '1 = 1'],
    'snake' => ['snake_scores', 'created_at', 'flagged = 0'],
    'neon' => ['neon_scores', 'created_at', 'flagged = 0'],
    'blockforge' => ['blockforge_scores', 'created_at', '1 = 1']
];
$tabs = ['all' => 'All games'] + array_intersect_key(array_map(static fn(array $game): string => $game[0], $games), $scoreSources);
$familyScores = array_fill_keys(array_keys($tabs), []);
$globalScores = $familyScores;
$combinedParts = [];
$unavailableGames = [];
$stats = ['Recorded plays' => 0, 'Plays today' => 0, 'Combined personal bests' => 0, 'Best Snake score' => 0];
$flashPlayedToday = false;

function gamesRows(PDO $db, string $sql, array $parameters = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($parameters);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function familyGameRanks(PDO $db, string $bestScores, int $familyId): array
{
    return gamesRows($db, "
        SELECT u.id, u.full_name AS name, SUM(s.best) AS points
        FROM ($bestScores) s JOIN users u ON u.id = s.user_id
        WHERE s.family_id = ?
        GROUP BY u.id, u.full_name
        ORDER BY points DESC, u.full_name, u.id LIMIT 5", [$familyId]);
}

function globalGameRanks(PDO $db, string $bestScores): array
{
    return gamesRows($db, "
        SELECT f.id, f.name, SUM(s.best) AS points, COUNT(DISTINCT s.user_id) AS members
        FROM ($bestScores) s JOIN families f ON f.id = s.family_id
        GROUP BY f.id, f.name
        ORDER BY points DESC, f.name, f.id LIMIT 10");
}

foreach ($scoreSources as $game => [$table, $dateColumn, $eligible]) {
    try {
        $personal = gamesRows($db, "
            SELECT COUNT(*) AS plays, MAX(score) AS best,
                COUNT(CASE WHEN $dateColumn >= CURRENT_DATE AND $dateColumn < CURRENT_DATE + INTERVAL 1 DAY THEN 1 END) AS today
            FROM $table WHERE user_id = ? AND $eligible", [$userId])[0];
        $stats['Recorded plays'] += (int)$personal['plays'];
        $stats['Plays today'] += (int)$personal['today'];
        $stats['Combined personal bests'] += (int)$personal['best'];
        if ($game === 'flash') {
            $flashPlayedToday = (int)$personal['today'] > 0;
        } elseif ($game === 'snake') {
            $stats['Best Snake score'] = (int)$personal['best'];
        }

        $best = "SELECT family_id, user_id, MAX(score) AS best FROM $table
            WHERE family_id IS NOT NULL AND $eligible GROUP BY family_id, user_id";
        $familyScores[$game] = familyGameRanks($db, $best, $familyId);
        $globalScores[$game] = globalGameRanks($db, $best);
        $combinedParts[] = $best;
    } catch (PDOException $e) {
        $unavailableGames[$game] = true;
        error_log("Games scores ($game): " . $e->getMessage());
    }
}
if ($combinedParts) {
    $combined = implode(' UNION ALL ', $combinedParts);
    try {
        $familyScores['all'] = familyGameRanks($db, $combined, $familyId);
        $globalScores['all'] = globalGameRanks($db, $combined);
    } catch (PDOException $e) {
        $unavailableGames['all'] = true;
        error_log('Combined games scores: ' . $e->getMessage());
    }
} else {
    $unavailableGames['all'] = true;
}

$pageTitle = 'Games';
$pageCSS = ['/games/css/games.css?build=' . filemtime(__DIR__ . '/css/games.css')];
require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="family-main games-main">
    <header class="family-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Games</h1>
            <p class="family-hero-summary">Play a quick round or get the family together for game night.</p>
            <div class="family-actions">
                <a href="#allGames" class="family-button family-button-primary">Pick a game</a>
                <a href="#familyLeaderboard" class="family-button">Leaderboards</a>
            </div>
        </div>
        <div class="family-hero-art" aria-hidden="true">
            <span class="games-art-board"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
        </div>
    </header>

    <dl class="games-stats" aria-label="Your recorded scores">
        <?php foreach ($stats as $label => $value): ?>
            <div><dt><?= $label ?></dt><dd><?= number_format($value) ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <section id="allGames" class="games-section" aria-labelledby="gamesHeading">
        <h2 class="family-section-title" id="gamesHeading">Pick a game</h2>
        <div class="games-grid">
            <?php foreach ($games as $key => [$name, $icon, $description, $directory, $tone, $type]): ?>
                <a class="game-card" data-tone="<?= $tone ?>" href="/games/<?= $directory ?>/" aria-labelledby="game-<?= $key ?>">
                    <div class="game-card-top">
                        <span class="game-icon" aria-hidden="true"><?= $icon ?></span>
                        <span class="game-type"><?= $type ?></span>
                    </div>
                    <h3 id="game-<?= $key ?>"><?= $name ?></h3>
                    <p><?= $description ?></p>
                    <div class="game-card-action">
                        <span>Play</span>
                        <?php if ($key === 'flash' && $flashPlayedToday): ?><small>Played today</small><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="games-boards">
        <?php
        $boards = [
            ['familyLeaderboard', 'Family leaderboard', $familyScores, $userId, 'You', 'Play a ranked game to put your family on the board.'],
            ['globalLeaderboard', 'Global family rankings', $globalScores, $familyId, 'Your family', 'No family scores are available for this game yet.']
        ];
        foreach ($boards as [$boardId, $title, $board, $currentId, $marker, $emptyText]):
        ?>
            <section class="games-section" id="<?= $boardId ?>" aria-labelledby="<?= $boardId ?>Title">
                <h2 class="family-section-title" id="<?= $boardId ?>Title"><?= $title ?></h2>
                <div class="games-board">
                    <div class="games-filters" role="group" aria-label="<?= $title ?> game filter" hidden>
                        <?php foreach ($tabs as $key => $label): ?>
                            <button type="button" data-game="<?= $key ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($board as $key => $rows): ?>
                        <div data-ranks="<?= $key ?>" <?= $key !== 'all' ? 'hidden' : '' ?>>
                            <?php if ($rows): ?>
                                <ol class="games-ranks">
                                    <?php foreach ($rows as $index => $row): ?>
                                        <?php $current = (int)$row['id'] === $currentId; ?>
                                        <li class="<?= $current ? 'is-current' : '' ?>">
                                            <span class="game-rank" aria-label="Rank <?= $index + 1 ?>"><?= $index + 1 ?></span>
                                            <div class="games-rank-name">
                                                <strong><?= htmlspecialchars($row['name'], ENT_QUOTES | ENT_SUBSTITUTE) ?></strong>
                                                <?php if ($current): ?><small><?= $marker ?></small><?php endif; ?>
                                                <?php if (isset($row['members'])): ?>
                                                    <?php $count = (int)$row['members']; ?>
                                                    <small><?= $count ?> <?= $count === 1 ? 'scoring member' : 'scoring members' ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <span class="games-score"><?= number_format((int)$row['points']) ?> <small>pts</small></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php else: ?>
                                <p class="games-empty"><?= isset($unavailableGames[$key]) ? 'These rankings are temporarily unavailable.' : $emptyText ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="games-sr-only" role="status"></p>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>

<script>
document.querySelectorAll('.games-board').forEach(board => {
    const filters = board.querySelector('.games-filters');
    const buttons = filters.querySelectorAll('button');
    const rankings = board.querySelectorAll('[data-ranks]');
    const status = board.querySelector('[role="status"]');
    filters.hidden = false;
    filters.addEventListener('click', event => {
        const button = event.target.closest('button[data-game]');
        if (!button || button.getAttribute('aria-pressed') === 'true') return;
        buttons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        rankings.forEach(panel => { panel.hidden = panel.dataset.ranks !== button.dataset.game; });
        status.textContent = `Showing ${button.textContent} rankings.`;
    });
});
</script>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
