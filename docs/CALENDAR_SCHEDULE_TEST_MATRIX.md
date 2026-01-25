# SECTION 9: TEST MATRIX
## Calendar & Schedule Rebuild

**Date:** 2026-01-03

---

## CRITICAL TEST CASES

### 1. Timezone Handling
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Create event in SAST (Africa/Johannesburg) | Stored with timezone, displayed correctly | ⏳ Pending |
| View event from different timezone | Time adjusted correctly | ⏳ Pending |
| Edit event doesn't change timezone | Original timezone preserved | ⏳ Pending |
| All-day event across timezones | Shows as full day in viewer's timezone | ⏳ Pending |

### 2. Recurring Events
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Create daily recurring (10 instances) | 10 events created with parent link | ⏳ Pending |
| Create weekly recurring | Events on same weekday | ⏳ Pending |
| Create weekday-only recurring | Skips weekends | ⏳ Pending |
| Edit single instance | Only that event changes | ⏳ Pending |
| Edit future instances | Current + future change | ⏳ Pending |
| Edit all instances | All events change | ⏳ Pending |
| Delete single instance | Only that event cancelled | ⏳ Pending |
| Delete future instances | Current + future cancelled | ⏳ Pending |
| Delete all instances | All events cancelled | ⏳ Pending |
| Parent date change updates children | All children shift proportionally | ⏳ Pending |

### 3. Reminder Sync
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Create event with reminder | Reminder created in event_reminders | ⏳ Pending |
| Update event time | Reminder next_trigger_at recalculated | ⏳ Pending |
| Update reminder minutes | next_trigger_at updated, is_sent reset | ⏳ Pending |
| Delete event | Reminder deleted via CASCADE | ⏳ Pending |
| Cancel event | Reminder marked as sent | ⏳ Pending |
| Complete event | Reminder marked as sent | ⏳ Pending |
| Multiple reminders per event | All trigger independently | ⏳ Pending |
| Snooze reminder 5/10/15/30/60 min | Snooze_count incremented, next_trigger updated | ⏳ Pending |

### 4. Offline → Online Sync
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Create event offline, sync online | Event created with queue entry | ⏳ Pending |
| Edit event offline, sync online | Changes applied, no conflict | ⏳ Pending |
| Edit event offline, already edited online | Conflict detected, stored for resolution | ⏳ Pending |
| Delete event offline, sync online | Event cancelled | ⏳ Pending |
| Multiple offline changes | All processed in order | ⏳ Pending |

### 5. Multi-User Conflicts
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Two users edit same event simultaneously | Conflict created, latest wins or manual | ⏳ Pending |
| User A deletes, User B edits | Edit fails gracefully | ⏳ Pending |
| Family member views other's calendar | Events visible (view permission) | ⏳ Pending |
| Parent edits child's event | Edit allowed (manage permission) | ⏳ Pending |
| Child tries to delete parent's event | Delete denied | ⏳ Pending |

### 6. Template Operations
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Apply template to single day | All template events created | ⏳ Pending |
| Apply template to date range | Events created for each day | ⏳ Pending |
| Apply template weekdays only | Weekend days skipped | ⏳ Pending |
| Apply template with exclusions | Excluded dates skipped | ⏳ Pending |
| Create template from existing day | Pattern captured correctly | ⏳ Pending |
| Template conflict detection | Conflicts reported, partial apply option | ⏳ Pending |

### 7. Focus Mode
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Start focus session | Status = in_progress, actual_start set | ⏳ Pending |
| End focus session with rating | Status = done, rating saved | ⏳ Pending |
| Pomodoro count increment | pomodoro_count += 1 | ⏳ Pending |
| Productivity stats update | schedule_productivity row updated | ⏳ Pending |

### 8. Undo Operations
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Undo event edit | Previous values restored | ⏳ Pending |
| Undo event delete | Event restored to pending | ⏳ Pending |
| Undo complete | Status back to pending | ⏳ Pending |
| Multiple undo | Each undo restores previous state | ⏳ Pending |
| Redo after undo | N/A (not implemented) | ⏳ Pending |

### 9. Bulk Operations
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Bulk mark done | All selected marked done | ⏳ Pending |
| Bulk change type | All selected type changed | ⏳ Pending |
| Bulk assign | All selected assigned to user | ⏳ Pending |
| Bulk delete | All selected cancelled | ⏳ Pending |
| Clear done for day | All done events cancelled | ⏳ Pending |

### 10. Edge Cases
| Test Case | Expected Result | Status |
|-----------|-----------------|--------|
| Event spanning midnight | Handled correctly | ⏳ Pending |
| Event ending before start | Validation error | ⏳ Pending |
| Empty title | Validation error | ⏳ Pending |
| Past event reminder | Skipped or marked sent | ⏳ Pending |
| Reminder 0 minutes | Triggers at event time | ⏳ Pending |
| 1000+ events query | Performance acceptable (<500ms) | ⏳ Pending |
| Concurrent writes | No data corruption | ⏳ Pending |

---

## INTEGRATION TEST SCENARIOS

### Scenario 1: Full Day Workflow
```
1. User creates morning study block (8-10 AM)
2. Adds 15-minute reminder
3. Creates recurring instance (weekdays)
4. Starts focus mode at 8 AM
5. Ends focus with 4-star rating
6. Views productivity stats
```

### Scenario 2: Family Calendar
```
1. Parent creates family event
2. Assigns to child
3. Child receives notification
4. Child views event (view permission)
5. Child cannot edit (permission check)
6. Parent updates event
7. All family members see update
```

### Scenario 3: Offline Sync
```
1. User goes offline
2. Creates 3 events
3. Edits 1 existing event
4. Comes back online
5. Sync processes queue
6. Conflicting edit detected
7. User resolves conflict
```

---

## PERFORMANCE BENCHMARKS

| Operation | Target | Measured |
|-----------|--------|----------|
| Load day events | <100ms | ⏳ |
| Load week events | <200ms | ⏳ |
| Load month events | <500ms | ⏳ |
| Create event | <100ms | ⏳ |
| Update event | <100ms | ⏳ |
| Apply template (5 events) | <500ms | ⏳ |
| Reminder cron (100 reminders) | <5s | ⏳ |
| Sync queue process (50 items) | <10s | ⏳ |

---

## BROWSER COMPATIBILITY

| Browser | Version | Status |
|---------|---------|--------|
| Chrome | Latest | ⏳ Pending |
| Firefox | Latest | ⏳ Pending |
| Safari | Latest | ⏳ Pending |
| Edge | Latest | ⏳ Pending |
| Chrome Mobile | Latest | ⏳ Pending |
| Safari iOS | Latest | ⏳ Pending |

---

## TEST EXECUTION LOG

| Date | Tester | Tests Run | Passed | Failed | Notes |
|------|--------|-----------|--------|--------|-------|
| - | - | - | - | - | - |
