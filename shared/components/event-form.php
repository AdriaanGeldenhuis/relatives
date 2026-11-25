<?php
$eventFormIsPage = isset($eventFormAction);
$eventFormValues = $eventFormValues ?? [];
$formValue = static fn (string $name): string => scheduleEscape($eventFormValues[$name] ?? '');
$selected = static fn (string $name, string $value): string => ($eventFormValues[$name] ?? '') === $value ? ' selected' : '';
$checked = static fn (string $name): string => ($eventFormValues[$name] ?? '') === '1' ? ' checked' : '';
?>
<form id="eventForm" class="modal-body"<?= $eventFormIsPage ? ' method="post" action="' . scheduleEscape($eventFormAction) . '"' : '' ?>>
    <?php if ($eventFormIsPage): ?>
        <input type="hidden" name="csrf_token" value="<?= scheduleEscape($csrfToken) ?>">
    <?php endif; ?>
    <?php if (!empty($eventFormError)): ?><p class="planner-form-error" role="alert"><?= scheduleEscape($eventFormError) ?></p><?php endif; ?>
    <p id="eventSourceNote" class="planner-form-hint" hidden>Linked note <a target="_blank" rel="noopener"></a></p>
    <div class="form-group">
        <label for="eventTitle">Title</label>
        <input name="title" value="<?= $formValue('title') ?>" id="eventTitle" class="form-control" maxlength="200" autocomplete="off" placeholder="What are you planning?" required autofocus>
    </div>
    <div class="form-row">
        <div class="form-group"><label for="eventDate">Start date</label><input name="date" value="<?= $formValue('date') ?>" id="eventDate" type="date" min="1000-01-01" max="9998-12-31" class="form-control" required></div>
        <div class="form-group"><label for="eventEndDate">End date</label><input name="end_date" value="<?= $formValue('end_date') ?>" id="eventEndDate" type="date" max="9998-12-31" class="form-control" required></div>
    </div>
    <div class="form-group"><label class="option-toggle"><input id="eventAllDay" name="all_day" type="checkbox" value="1"<?= $checked('all_day') ?>> All day</label></div>
    <div class="form-row" id="eventTimeFields">
        <div class="form-group"><label for="eventStart">Start time</label><input name="start_time" value="<?= $formValue('start_time') ?>" id="eventStart" type="time" class="form-control"></div>
        <div class="form-group"><label for="eventEnd">End time</label><input name="end_time" value="<?= $formValue('end_time') ?>" id="eventEnd" type="time" class="form-control"></div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="eventType">Type</label>
            <select name="kind" id="eventType" class="form-control">
                <?php foreach ($types as $value => $info): ?><option value="<?= $value ?>"<?= $selected('kind', $value) ?>><?= $info['name'] ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="eventPriority">Priority</label>
            <select name="priority" id="eventPriority" class="form-control">
                <option value="low"<?= $selected('priority', 'low') ?>>Low</option><option value="medium"<?= $selected('priority', 'medium') ?>>Normal</option><option value="high"<?= $selected('priority', 'high') ?>>High</option><option value="urgent"<?= $selected('priority', 'urgent') ?>>Urgent</option>
            </select>
        </div>
    </div>
    <div class="form-group">
        <label for="eventAssign">Assigned to</label>
        <select name="assigned_to" id="eventAssign" class="form-control">
            <option value="">Unassigned</option>
            <?php foreach ($familyMembers as $member): ?><option value="<?= (int)$member['id'] ?>"<?= $selected('assigned_to', (string) $member['id']) ?>><?= scheduleEscape($member['full_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="eventLocation">Location</label>
        <input name="location" value="<?= $formValue('location') ?>" id="eventLocation" class="form-control" maxlength="200" placeholder="Add a location…">
    </div>
    <div class="form-group">
        <label for="eventNotes">Notes</label>
        <textarea name="notes" id="eventNotes" class="form-control" rows="3" placeholder="Add details…"><?= $formValue('notes') ?></textarea>
    </div>
    <details class="planner-form-more" id="eventOptions"<?= $eventFormIsPage && !empty($eventFormError) ? ' open' : '' ?>>
        <summary>Reminder, repeat and color</summary>
        <div class="form-row">
            <div class="form-group"><label for="eventReminder">Reminder (minutes before)</label><input name="reminder_minutes" value="<?= $formValue('reminder_minutes') ?>" id="eventReminder" type="number" min="0" max="1440" class="form-control" placeholder="None"></div>
            <div class="form-group">
                <label for="eventRepeat">Repeat</label>
                <select name="repeat_rule" id="eventRepeat" class="form-control">
                    <option value="">Doesn’t repeat</option><option value="daily"<?= $selected('repeat_rule', 'daily') ?>>Daily</option><option value="weekdays"<?= $selected('repeat_rule', 'weekdays') ?>>Weekdays</option><option value="weekly"<?= $selected('repeat_rule', 'weekly') ?>>Weekly</option><option value="monthly"<?= $selected('repeat_rule', 'monthly') ?>>Monthly</option><option value="yearly"<?= $selected('repeat_rule', 'yearly') ?>>Yearly</option>
                </select>
            </div>
        </div>
        <p class="planner-form-hint" id="eventRepeatHint" hidden></p>
        <div class="form-row">
            <div class="form-group"><label for="eventColor">Color</label><input name="color" value="<?= $formValue('color') ?>" id="eventColor" type="color" class="form-control"></div>
            <div class="form-group"><label class="option-toggle"><input name="focus_mode" id="eventFocus" type="checkbox" value="1"<?= $checked('focus_mode') ?>> Enable focus timer</label></div>
        </div>
    </details>
    <p class="planner-form-error" id="eventFormError" role="alert" hidden></p>
    <footer class="modal-actions">
        <?php if ($eventFormIsPage): ?>
            <a class="btn btn-secondary" href="<?= scheduleEscape($eventFormBack) ?>">Cancel</a>
        <?php else: ?>
            <button type="button" class="btn btn-secondary" data-close-event-editor>Cancel</button>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Save event</button>
    </footer>
</form>
