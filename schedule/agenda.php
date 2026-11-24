<?php
$entries = $viewMode === 'week' ? $weekEvents : $events;
$total = count($entries);
$done = count(array_filter($entries, static fn($event) => $event['status'] === 'done'));
$percent = $total ? round(100 * $done / $total) : 0;
$weekLast = $weekStart->modify('+6 days');
$dateLabel = $viewMode === 'week'
    ? $weekStart->format($weekStart->format('m') === $weekLast->format('m') ? 'j' : 'j M') . ' – ' . $weekLast->format('j M Y')
    : $day->format('l, j F Y');
?>
<div id="scheduleDatePanel">
    <div class="planner-toolbar">
        <div class="planner-views" aria-label="Schedule views">
            <a href="?date=<?= $selectedDate ?>&amp;view=day" data-schedule-view="day" <?= $viewMode === 'day' ? 'aria-current="page"' : '' ?>>Day</a>
            <a href="?date=<?= $selectedDate ?>&amp;view=week" data-schedule-view="week" <?= $viewMode === 'week' ? 'aria-current="page"' : '' ?>>Week</a>
            <a href="/schedule/timeline/?date=<?= $selectedDate ?>">Timeline</a>
        </div>
        <div class="planner-date-actions">
            <button type="button" class="planner-icon-button" onclick="changeDate(-1)" aria-label="Previous <?= $viewMode ?>"><span aria-hidden="true">‹</span></button>
            <button type="button" class="planner-control" onclick="goToToday()">Today</button>
            <button type="button" class="planner-icon-button" onclick="changeDate(1)" aria-label="Next <?= $viewMode ?>"><span aria-hidden="true">›</span></button>
            <button type="button" class="planner-control" onclick="showDatePicker()">Pick date</button>
        </div>
    </div>
    <header class="planner-date-heading">
        <div>
            <h2 tabindex="-1" id="scheduleDateHeading"><?= $dateLabel ?></h2>
            <p><?= $total ?> <?= $total === 1 ? 'event' : 'events' ?><?= $viewMode === 'day' && $selectedDate === date('Y-m-d') ? ' · Today' : '' ?></p>
        </div>
        <?php if ($viewMode === 'day' && $events): ?>
            <button type="button" id="bulkModeBtn" class="planner-control" onclick="toggleBulkMode()" aria-pressed="false">Select events</button>
        <?php endif; ?>
    </header>

    <?php if ($viewMode === 'week'): ?>
        <div class="planner-week" aria-label="Week agenda">
            <?php for ($offset = 0; $offset < 7; $offset++):
                $date = $weekStart->modify("+$offset days");
                $dateKey = $date->format('Y-m-d');
                $daily = scheduleOnDay($weekEvents, $date);
                ?>
                <section class="planner-week-day<?= $dateKey === date('Y-m-d') ? ' is-today' : '' ?>">
                    <h3><a href="?date=<?= $dateKey ?>&amp;view=day" data-schedule-view="day">
                        <span><?= $date->format('D') ?></span> <?= $date->format('j M') ?>
                    </a></h3>
                    <div class="planner-week-entries">
                        <?php foreach (array_slice($daily, 0, 4) as $item): ?>
                            <a class="planner-week-entry<?= $item['status'] === 'done' ? ' is-done' : '' ?>" href="?date=<?= $dateKey ?>&amp;view=day" data-schedule-view="day">
                                <span><?= scheduleDayTime($item, $date) ?></span><?= scheduleEscape($item['title']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($daily) > 4): ?>
                            <a href="?date=<?= $dateKey ?>&amp;view=day" data-schedule-view="day" class="planner-week-more">+<?= count($daily) - 4 ?> more</a>
                        <?php elseif (!$daily): ?>
                            <p class="planner-week-empty">No events</p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endfor; ?>
        </div>
    <?php else: ?>
        <div class="planner-board">
            <section class="planner-agenda" aria-label="Day agenda">
                <?php if ($events): ?>
                    <div class="planner-filters">
                        <label><span class="planner-sr-only">Search agenda</span><input type="search" id="scheduleSearch" placeholder="Search this day" autocomplete="off"></label>
                        <label><span class="planner-sr-only">Filter by status</span>
                            <select id="scheduleStatus">
                                <option value="all">All statuses</option>
                                <option value="open">Open</option>
                                <option value="done">Completed</option>
                            </select>
                        </label>
                        <label><span class="planner-sr-only">Filter by assignee</span>
                            <select id="scheduleAssignee">
                                <option value="all">Everyone</option>
                                <option value="unassigned">Unassigned</option>
                                <?php foreach ($familyMembers as $member): ?>
                                    <option value="<?= (int)$member['id'] ?>"><?= scheduleEscape($member['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                <?php endif; ?>
                <div id="bulkActionsBar" class="planner-bulk" hidden>
                    <span><strong id="bulkSelectedCount">0</strong> selected</span>
                    <button type="button" onclick="selectVisibleEvents()" class="planner-control">Select visible</button>
                    <button type="button" onclick="bulkMarkDone()" class="planner-control" data-bulk-action disabled>Complete</button>
                    <button type="button" onclick="showBulkTypeModal()" class="planner-control" data-bulk-action disabled>Type</button>
                    <button type="button" onclick="showBulkAssignModal()" class="planner-control" data-bulk-action disabled>Assign</button>
                    <button type="button" onclick="bulkDelete()" class="planner-control planner-danger" data-bulk-action disabled>Delete</button>
                    <button type="button" onclick="toggleBulkMode()" class="planner-control">Cancel</button>
                </div>
                <?php if (!$events): ?>
                    <div class="planner-empty">
                        <span aria-hidden="true">＋</span>
                        <h3>Nothing scheduled</h3>
                        <p>Add an event to <?= $day->format('l') ?>’s agenda.</p>
                        <button type="button" class="btn btn-primary" onclick="showQuickAdd()">Add event</button>
                    </div>
                <?php else: ?>
                    <div class="planner-entries">
                        <?php foreach ($events as $item):
                            $id = (int)$item['id'];
                            $status = in_array($item['status'], ['done', 'in_progress'], true) ? $item['status'] : 'pending';
                            $kind = $types[$item['kind']] ?? $types['other'];
                            $color = preg_match('/^#[0-9a-f]{6}$/iD', $item['color'] ?? '') ? $item['color'] : $kind['color'];
                            $notes = $item['notes'] ?: $item['description'];
                            ?>
                            <article class="planner-event event-card <?= $status ?>" data-event-id="<?= $id ?>" style="--event-accent: <?= $color ?>">
                                <div class="planner-event-time">
                                    <strong><?= scheduleDayTime($item, $day) ?></strong>
                                    <?php if ($minutes = scheduleMinutesOnDay($item, $day)): ?><span><?= scheduleDuration($minutes) ?></span><?php endif; ?>
                                </div>
                                <div class="planner-event-content">
                                    <div class="planner-event-meta">
                                        <span class="planner-kind"><?= $kind['name'] ?></span>
                                        <?php if (in_array($item['priority'], ['high', 'urgent'], true)): ?><span class="planner-priority"><?= ucfirst($item['priority']) ?></span><?php endif; ?>
                                        <?php if ($status === 'in_progress'): ?><span>In progress</span><?php endif; ?>
                                        <?php if ($item['repeat_rule']): ?><span>Repeats</span><?php endif; ?>
                                    </div>
                                    <h3><button type="button" onclick="editEvent(<?= $id ?>)"><?= scheduleEscape($item['title']) ?></button></h3>
                                    <?php if ($notes): ?><p class="planner-event-notes"><?= scheduleEscape($notes) ?></p><?php endif; ?>
                                    <div class="planner-event-meta">
                                        <?php if ($item['assigned_to_name']): ?><span><?= scheduleEscape($item['assigned_to_name']) ?></span><?php endif; ?>
                                        <?php if ($item['reminder_minutes']): ?><span>Reminder <?= (int)$item['reminder_minutes'] ?>m before</span><?php endif; ?>
                                        <?php if ($item['productivity_rating']): ?><span>Rating <?= (int)$item['productivity_rating'] ?>/5</span><?php endif; ?>
                                    </div>
                                </div>
                                <div class="planner-event-actions">
                                    <?php if ($item['focus_mode'] && $status === 'pending' && !$item['all_day']
                                        && $item['ends_at'] > $item['starts_at'] && (int)$item['user_id'] === (int)$user['id']): ?>
                                        <button type="button" class="planner-control" onclick="startFocusSession(<?= $id ?>)">Start focus</button>
                                    <?php endif; ?>
                                    <details class="planner-event-menu">
                                        <summary class="planner-icon-button" aria-label="Actions for <?= scheduleEscape($item['title']) ?>">···</summary>
                                        <div>
                                            <button type="button" onclick="editEvent(<?= $id ?>)">Edit</button>
                                            <button type="button" onclick="duplicateEvent(<?= $id ?>)">Duplicate</button>
                                            <button type="button" class="planner-danger" onclick="deleteEvent(<?= $id ?>)">Delete</button>
                                        </div>
                                    </details>
                                    <label class="planner-complete">
                                        <input type="checkbox" <?= $status === 'done' ? 'checked' : '' ?> <?= $status === 'in_progress' && $item['focus_mode'] ? 'disabled' : '' ?> onchange="toggleEvent(<?= $id ?>, this)">
                                        <span class="planner-sr-only">Complete <?= scheduleEscape($item['title']) ?></span>
                                    </label>
                                    <label class="planner-select" hidden>
                                        <input type="checkbox" class="bulk-checkbox" value="<?= $id ?>">
                                        <span class="planner-sr-only">Select <?= scheduleEscape($item['title']) ?></span>
                                    </label>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="planner-no-results" id="scheduleNoResults" role="status" hidden>No events match those filters.</p>
                <?php endif; ?>
            </section>
            <aside class="planner-sidebar" aria-label="Day summary">
                <div class="planner-panel">
                    <h3>Day progress</h3>
                    <p class="planner-score"><?= $done ?> <small>of <?= $total ?> complete</small></p>
                    <progress value="<?= $percent ?>" max="100" aria-label="Day completion"></progress>
                    <?php if ($done): ?><button type="button" class="planner-link" onclick="clearDone()">Clear completed</button><?php endif; ?>
                </div>
                <div class="planner-panel">
                    <h3>Week of <?= $weekStart->format('j M') ?></h3>
                    <?php foreach (['work', 'study', 'focus'] as $type):
                        $matching = array_filter($weekEvents, static fn($event) => $event['kind'] === $type);
                        $completed = count(array_filter($matching, static fn($event) => $event['status'] === 'done')); ?>
                        <p class="planner-week-stat"><span><?= $types[$type]['name'] ?></span><strong><?= $completed ?> / <?= count($matching) ?></strong></p>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>
