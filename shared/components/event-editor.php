<dialog id="eventEditor" class="planner-modal" aria-labelledby="eventEditorHeading">
    <div class="modal-content">
        <header class="modal-header">
            <h2 id="eventEditorHeading">Add event</h2>
            <button type="button" class="modal-close" aria-label="Close" data-close-event-editor>&times;</button>
        </header>
        <form id="eventForm" class="modal-body">
            <p id="eventSourceNote" class="planner-form-hint" hidden>Linked note <a target="_blank" rel="noopener"></a></p>
            <div class="form-group">
                <label for="eventTitle">Title</label>
                <input name="title" id="eventTitle" class="form-control" maxlength="200" autocomplete="off" placeholder="What are you planning?" required autofocus>
            </div>
            <div class="form-row">
                <div class="form-group"><label for="eventDate">Start date</label><input name="date" id="eventDate" type="date" class="form-control" required></div>
                <div class="form-group"><label for="eventEndDate">End date</label><input name="end_date" id="eventEndDate" type="date" class="form-control" required></div>
            </div>
            <div class="form-group"><label class="option-toggle"><input id="eventAllDay" name="all_day" type="checkbox" value="1"> All day</label></div>
            <div class="form-row" id="eventTimeFields">
                <div class="form-group"><label for="eventStart">Start time</label><input name="start_time" id="eventStart" type="time" class="form-control"></div>
                <div class="form-group"><label for="eventEnd">End time</label><input name="end_time" id="eventEnd" type="time" class="form-control"></div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="eventType">Type</label>
                    <select name="kind" id="eventType" class="form-control">
                        <?php foreach ($types as $value => $info): ?><option value="<?= $value ?>"><?= $info['name'] ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="eventPriority">Priority</label>
                    <select name="priority" id="eventPriority" class="form-control">
                        <option value="low">Low</option><option value="medium">Normal</option><option value="high">High</option><option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="eventAssign">Assigned to</label>
                <select name="assigned_to" id="eventAssign" class="form-control">
                    <option value="">Unassigned</option>
                    <?php foreach ($familyMembers as $member): ?><option value="<?= (int)$member['id'] ?>"><?= scheduleEscape($member['full_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="eventLocation">Location</label>
                <input name="location" id="eventLocation" class="form-control" maxlength="200" placeholder="Add a location…">
            </div>
            <div class="form-group">
                <label for="eventNotes">Notes</label>
                <textarea name="notes" id="eventNotes" class="form-control" rows="3" placeholder="Add details…"></textarea>
            </div>
            <details class="planner-form-more" id="eventOptions">
                <summary>Reminder, repeat and color</summary>
                <div class="form-row">
                    <div class="form-group"><label for="eventReminder">Reminder (minutes before)</label><input name="reminder_minutes" id="eventReminder" type="number" min="0" max="1440" class="form-control" placeholder="None"></div>
                    <div class="form-group">
                        <label for="eventRepeat">Repeat</label>
                        <select name="repeat_rule" id="eventRepeat" class="form-control">
                            <option value="">Doesn’t repeat</option><option value="daily">Daily</option><option value="weekdays">Weekdays</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="yearly">Yearly</option>
                        </select>
                    </div>
                </div>
                <p class="planner-form-hint" id="eventRepeatHint" hidden></p>
                <div class="form-row">
                    <div class="form-group"><label for="eventColor">Color</label><input name="color" id="eventColor" type="color" class="form-control"></div>
                    <div class="form-group"><label class="option-toggle"><input name="focus_mode" id="eventFocus" type="checkbox" value="1"> Enable focus timer</label></div>
                </div>
            </details>
            <p class="planner-form-error" id="eventFormError" role="alert" hidden></p>
            <footer class="modal-actions">
                <button type="button" class="btn btn-secondary" data-close-event-editor>Cancel</button>
                <button type="submit" class="btn btn-primary">Save event</button>
            </footer>
        </form>
    </div>
</dialog>
