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

// Title, icon, description, directory, card color, label and play modes.
$games = [
    'flash' => ['Flash Challenge', '⚡', 'Race the clock in a daily 30-second trivia challenge.', 'flash_challenge', 'honey', 'Daily trivia', 'solo'],
    'snake' => ['Snake Classic', '🐍', 'Eat, grow and see how long you can keep going.', 'snake', 'blue', 'Arcade · Offline', 'solo offline'],
    'neon' => ['Neon Nibbler', '💠', 'Collect sparks and dodge sentinels as you explore the maze.', 'neon_nibbler', 'sky', 'Maze chase · Offline', 'solo offline'],
    'blockforge' => ['BlockForge', '🧱', 'Clear lines in solo play, a daily puzzle or the family board.', 'blockforge', 'rose', 'Block puzzle · Offline', 'solo family offline'],
    '30seconds' => ['30 Seconds Party', '⏱️', 'Split into teams and help your partner guess the words before time runs out.', '30_seconds_offline', 'lilac', 'Team game · Offline', 'family offline'],
    'memory' => ['Memory Match', '🧠', 'Match free tiles to clear the board. Choose from three layouts.', 'mahjong_solitaire', 'cream', 'Tile puzzle', 'solo']
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
$personalBests = [];
$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$utc = new DateTimeZone('UTC');
$utcScoreWindow = [$today->setTimezone($utc)->format('Y-m-d H:i:s'), $tomorrow->setTimezone($utc)->format('Y-m-d H:i:s')];

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
        // BlockForge writes UTC into a DATETIME. Flash dates and the other score queries use local dates.
        [$start, $end] = $game === 'blockforge' ? $utcScoreWindow : [$today->format('Y-m-d'), $tomorrow->format('Y-m-d')];
        $personal = gamesRows($db, "
            SELECT COUNT(*) AS plays, MAX(score) AS best,
                COUNT(CASE WHEN $dateColumn >= ? AND $dateColumn < ? THEN 1 END) AS today
            FROM $table WHERE user_id = ? AND $eligible", [$start, $end, $userId])[0];
        if ($personal['best'] !== null) $personalBests[$game] = (int)$personal['best'];
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
$pageJS = ['/games/js/games.js'];
$pageJSVersion = filemtime(__DIR__ . '/js/games.js');
require_once __DIR__ . '/../shared/components/header.php';
?>

<main class="family-main games-main">
    <header class="family-hero app-hero photo-hero photo-hero-wide games-hero">
        <div class="family-hero-content">
            <h1 class="family-hero-title">Family arcade</h1>
            <div class="games-daily">
                <span aria-hidden="true">⚡</span>
                <div>
                    <strong>Flash Challenge</strong>
                    <p class="family-hero-summary"><?= $flashPlayedToday ? 'You played today' : 'Daily trivia · 30 seconds' ?></p>
                </div>
            </div>
            <div class="family-actions">
                <a href="/games/flash_challenge/" class="family-button family-button-primary"><?= $flashPlayedToday ? 'See today’s result' : 'Play today’s challenge' ?></a>
                <a href="#leaderboards" class="family-button">Leaderboards</a>
            </div>
        </div>
        <?php renderFamilyScene('games'); ?>
    </header>

    <section id="allGames" class="games-section" aria-labelledby="gamesHeading">
        <div class="games-heading">
            <h2 class="family-section-title" id="gamesHeading">Pick a game</h2>
            <button type="button" class="family-button games-surprise" id="gamesSurprise" hidden>Surprise me</button>
        </div>
        <div class="games-filters" id="gamesModes" role="group" aria-label="How to play" hidden>
            <?php foreach (['all' => 'All games', 'solo' => 'Solo', 'family' => 'Family', 'offline' => 'Offline'] as $mode => $label): ?>
                <button type="button" data-mode="<?= $mode ?>" aria-pressed="<?= $mode === 'all' ? 'true' : 'false' ?>"><?= $label ?></button>
            <?php endforeach; ?>
        </div>
        <div class="games-suggestion" id="gamesSuggestion" hidden>
            <div><span>Try this</span><h3 id="gamesSuggestionName"></h3></div>
            <a class="family-button family-button-primary" id="gamesSuggestionLink">Play</a>
        </div>
        <p class="games-sr-only" id="gamesBrowseStatus" role="status"></p>
        <div class="games-grid">
            <?php foreach ($games as $key => [$name, $icon, $description, $directory, $tone, $type, $modes]): ?>
                <a class="game-card" data-tone="<?= $tone ?>" data-play-modes="<?= $modes ?>"
                   href="/games/<?= $directory ?>/" aria-labelledby="game-<?= $key ?>">
                    <div class="game-card-top">
                        <span class="game-icon" aria-hidden="true"><?= $icon ?></span>
                        <span class="game-type"><?= $type ?></span>
                    </div>
                    <h3 id="game-<?= $key ?>"><?= $name ?></h3>
                    <p><?= $description ?></p>
                    <div class="game-card-action">
                        <span>Play</span>
                        <?php if ($key === 'flash' && $flashPlayedToday): ?>
                            <small>Played today</small>
                        <?php elseif (isset($personalBests[$key])): ?>
                            <small>Your best <?= number_format($personalBests[$key]) ?></small>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <details class="games-personal">
        <summary>Your game stats</summary>
        <dl class="games-stats">
            <?php foreach ($stats as $label => $value): ?>
                <div><dt><?= $label ?></dt><dd><?= number_format($value) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </details>

    <section id="leaderboards" class="games-section" aria-labelledby="leaderboardsTitle">
        <h2 class="family-section-title" id="leaderboardsTitle">Leaderboards</h2>
        <div class="games-board-tools" id="gamesBoardTools" hidden>
            <div class="games-filters" role="group" aria-label="Leaderboard scope">
                <button type="button" data-board="familyLeaderboard" aria-pressed="true">Your family</button>
                <button type="button" data-board="globalLeaderboard" aria-pressed="false">All families</button>
            </div>
            <label>Game
                <select id="gamesRankingGame">
                    <?php foreach ($tabs as $key => $label): ?>
                        <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div class="games-boards">
            <?php
            $boards = [
                ['familyLeaderboard', 'Family leaderboard', $familyScores, $userId, 'You', 'Play a ranked game to put your family on the board.'],
                ['globalLeaderboard', 'Global family rankings', $globalScores, $familyId, 'Your family', 'No family scores are available for this game yet.']
            ];
            foreach ($boards as [$boardId, $title, $board, $currentId, $marker, $emptyText]):
            ?>
                <section class="games-board" id="<?= $boardId ?>" data-score-board aria-labelledby="<?= $boardId ?>Title">
                    <h3 id="<?= $boardId ?>Title"><?= $title ?></h3>
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
                </section>
            <?php endforeach; ?>
        </div>
        <p class="games-sr-only" id="gamesRankingStatus" role="status"></p>
    </section>
</main>

<?php require_once __DIR__ . '/../shared/components/footer.php'; ?>
