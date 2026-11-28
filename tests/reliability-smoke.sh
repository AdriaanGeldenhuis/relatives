#!/usr/bin/env bash
set -euo pipefail

db_container=${1:?Pass the disposable MariaDB container ID}
db_port=${2:-3307}
smoke_root=$(mktemp -d)
smoke_server=
cleanup() {
    if [[ $? != 0 && -f $smoke_root/server.log ]]; then tail -30 "$smoke_root/server.log" >&2; fi
    if [[ -n $smoke_server ]]; then kill "$smoke_server" 2>/dev/null || true; fi
    rm -rf "$smoke_root"
}
trap cleanup EXIT

sql() {
    docker exec -i -e MYSQL_PWD=release-fixture "$db_container" mariadb -uroot relatives_release_ci "$@"
}
sql < tests/reliability-fixture.sql
for migration in 001_tracking_tables 002_place_state 003_tracking_reliability 004_tracking_evidence; do
    sql < "tracking/migrations/$migration.sql"
done
sql < migrations/app-reliability-v1.sql
sql < migrations/app-reliability-v1.sql
fixture_hash=$(php -r 'echo password_hash("local-fixture-only", PASSWORD_BCRYPT);')
sql <<SQL
SET time_zone = '+02:00';
INSERT INTO families (id,name,trial_ends_at) VALUES
    (1,'Fixture family',NOW()+INTERVAL 2 DAY),(2,'Other family',NOW()+INTERVAL 2 DAY);
INSERT INTO users (id,family_id,role,full_name,email,password_hash) VALUES
    (1,1,'owner','Fixture sender','sender@example.invalid','$fixture_hash'),
    (2,1,'member','Fixture recipient','recipient@example.invalid','$fixture_hash'),
    (3,2,'owner','Other family','other@example.invalid','$fixture_hash');
INSERT INTO fcm_tokens (user_id,token) VALUES (2,'fixture-token');
INSERT INTO events (id,family_id,user_id,title,starts_at,kind,reminder_minutes)
    VALUES (10,1,2,'Fixture reminder',NOW()+INTERVAL 20 MINUTE,'event',30);
SQL

git archive "${RELATIVES_SMOKE_TREE:-HEAD}" | tar -xf - -C "$smoke_root"
mkdir "$smoke_root/logs"
cat >"$smoke_root/.env" <<ENV
DB_HOST=127.0.0.1;port=$db_port
DB_NAME=relatives_release_ci
DB_USER=root
DB_PASS=release-fixture
APP_URL=http://localhost:8098
APP_ENV=development
ENV
php -S 127.0.0.1:8098 -t "$smoke_root" >"$smoke_root/server.log" 2>&1 &
smoke_server=$!
base=http://localhost:8098
for wait_step in {1..15}; do
    if curl -fsS "$base/login.php" >/dev/null 2>&1; then break; fi
    sleep 1
done

for actor in sender recipient other; do
    code=$(curl -fsS -o /dev/null -w '%{http_code}' -c "$smoke_root/$actor.cookie" \
        --data-urlencode "email=$actor@example.invalid" --data-urlencode password=local-fixture-only "$base/login.php")
    [[ $code == 303 ]]
done
csrf=$(curl -fsS -b "$smoke_root/sender.cookie" "$base/messages/" |
    sed -n 's/.*id="sendBtn" data-csrf="\([a-f0-9]*\)".*/\1/p')
[[ -n $csrf ]]
chat_csrf() {
    curl -fsS -b "$smoke_root/$1.cookie" "$base/messages/" |
        sed -n 's/.*id="sendBtn" data-csrf="\([a-f0-9]*\)".*/\1/p'
}

send_message() {
    curl -fsS -b "$smoke_root/sender.cookie" -H "X-CSRF-Token: $csrf" \
        --data-urlencode client_message_id=476aaf03-256c-4f5b-a59d-0cb2c1478921 \
        --data-urlencode 'content=Fixture message' "$base/messages/api/send.php" |
        php -r '$r=json_decode(stream_get_contents(STDIN), true); if (!$r["success"]) exit(1); echo $r["message_id"];'
}
id=$(send_message)
[[ $id =~ ^[0-9]+$ && $(send_message) == "$id" ]]
[[ $(sql -Nse "SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='message'") == 1 ]]
[[ $(sql -Nse 'SELECT COUNT(*) FROM push_deliveries') == 1 ]]

delta() {
    curl -fsS -b "$smoke_root/$1.cookie" "$base/messages/api/fetch.php?cursor=$2"
}
delta recipient 0 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit(($r["count"] === 1 && $r["cursor"] === "1") ? 0 : 1);'
delta other 0 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["count"] === 0 ? 0 : 1);'
[[ $(sql -Nse "SELECT action_url FROM notifications WHERE user_id=2 AND type='message'") == "/messages/?message=$id" ]]
read_chat() {
    curl -fsS -b "$smoke_root/$1.cookie" -H "X-CSRF-Token: $2" -H 'Content-Type: application/json' \
        --data "{\"message_ids\":[\"$id\"]}" "$base/messages/api/read.php"
}
read_chat other "$(chat_csrf other)" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["success"] && !$r["message_ids"] ? 0 : 1);'
recipient_csrf=$(chat_csrf recipient)
read_chat recipient "$recipient_csrf" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["marked_count"] === 1 && $r["notifications_read"] === 1 ? 0 : 1);'
read_chat recipient "$recipient_csrf" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["marked_count"] === 0 && $r["notifications_read"] === 0 ? 0 : 1);'
[[ $(sql -Nse "SELECT COUNT(*) FROM message_reads WHERE message_id=$id AND user_id=2") == 1 ]]
sql -e 'UPDATE push_deliveries SET next_at=NOW()'
php "$smoke_root/cron/push-deliveries.php"
[[ $(sql -Nse 'SELECT reason FROM push_deliveries') == already_read ]]
sql -e "UPDATE messages SET content='Edited fixture message', edited_at=NOW() WHERE id=$id"
delta recipient 1 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["messages"][0]["content"] === "Edited fixture message" ? 0 : 1);'
sql -e "UPDATE messages SET deleted_at=NOW() WHERE id=$id"
delta recipient 2 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit(count($r["removed"]) === 1 ? 0 : 1);'

{
    printf 'INSERT INTO messages (id,family_id,user_id,content) VALUES '
    for entry in {1001..1140}; do
        printf "(%s,1,1,'History %s')," "$entry" "$entry"
    done
    printf "(1141,2,3,'Another family');\n"
} | sql
history_page() {
    curl -fsS -b "$smoke_root/recipient.cookie" "$base/messages/api/fetch.php?$1"
}
history_page 'message=1060' | php -r '
    $r=json_decode(stream_get_contents(STDIN), true);
    exit(array_column($r["messages"],"id") === range(1035,1085) && $r["has_older"] && $r["has_newer"] && $r["focused"] ? 0 : 1);'
history_page 'before=1035' | php -r '
    $r=json_decode(stream_get_contents(STDIN), true);
    exit(array_column($r["messages"],"id") === range(1001,1034) && !$r["has_older"] ? 0 : 1);'
history_page 'after=1085' | php -r '
    $r=json_decode(stream_get_contents(STDIN), true);
    exit(array_column($r["messages"],"id") === range(1086,1135) && $r["has_newer"] ? 0 : 1);'
history_page 'message=1141' | php -r '
    $r=json_decode(stream_get_contents(STDIN), true);
    exit(!$r["focused"] && end($r["messages"])["id"] === 1140 ? 0 : 1);'

printf 'fixture chat file\n' >"$smoke_root/chat-file.txt"
receipt_id=1bdbe898-a1b4-418c-906c-c880ffd8dc07
curl -fsS -b "$smoke_root/sender.cookie" -H "X-CSRF-Token: $csrf" \
    -F "client_message_id=$receipt_id" -F "media[]=@$smoke_root/chat-file.txt;filename=Family trip plan.txt" \
    "$base/messages/api/send.php" >"$smoke_root/chat-file.json"
read -r attachment_message attachment_url < <(php -r '
    $r=json_decode(file_get_contents($argv[1]), true); $file=$r["sent_message"]["attachments"][0] ?? [];
    if (($file["name"] ?? "") !== "Family trip plan.txt") exit(1);
    echo "$r[message_id] $file[path]\n";
' "$smoke_root/chat-file.json")
curl -fsS -b "$smoke_root/recipient.cookie" -D "$smoke_root/chat-file.headers" \
    -H 'Range: bytes=1-4' "$base$attachment_url" >"$smoke_root/chat-file.range"
grep -q ' 206 ' "$smoke_root/chat-file.headers"
grep -qi 'filename="Family trip plan.txt"' "$smoke_root/chat-file.headers"
[[ $(cat "$smoke_root/chat-file.range") == ixtu ]]
[[ $(curl -sS -b "$smoke_root/other.cookie" -o /dev/null -w '%{http_code}' "$base$attachment_url") == 404 ]]
[[ $(curl -sS -o /dev/null -w '%{http_code}' "$base$attachment_url") == 401 ]]
curl -fsS -b "$smoke_root/sender.cookie" "$base/messages/api/fetch.php?pending=$receipt_id" |
    php -r '$data=json_decode(stream_get_contents(STDIN), true); exit($data["acknowledged"]==[$argv[1]] ? 0 : 1);' "$receipt_id"
history_page "pending=$receipt_id" |
    php -r '$data=json_decode(stream_get_contents(STDIN), true); exit($data["acknowledged"]===[] ? 0 : 1);'
curl -fsS -b "$smoke_root/recipient.cookie" -G --data-urlencode 'query=FAMILY TRIP' "$base/messages/api/search.php" |
    php -r '$data=json_decode(stream_get_contents(STDIN), true); exit($data["total"]===1 && $data["results"][0]["id"]===$argv[1] ? 0 : 1);' "$attachment_message"
sql -e 'UPDATE fcm_tokens SET wake_session_hash=(SELECT session_token FROM sessions WHERE user_id=2 ORDER BY id DESC LIMIT 1) WHERE user_id=2'
alert_receipt=$(sql -Nse "SELECT d.receipt FROM push_deliveries d JOIN notifications n ON n.id=d.notification_id
    WHERE n.user_id=2 AND n.action_url='/messages/?message=$attachment_message' AND d.channel='fcm'")
[[ $alert_receipt =~ ^[a-f0-9]{32}$ ]]
mark_alert() {
    curl -sS -b "$smoke_root/$1.cookie" -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' \
        --data "{\"receipt\":\"$alert_receipt\",\"action\":\"mark_read\"}" "$base/api/push/receipt.php"
}
[[ $(mark_alert other) == 404 && $(mark_alert recipient) == 204 && $(mark_alert recipient) == 204 ]]
[[ $(sql -Nse "SELECT is_read FROM notifications WHERE user_id=2 AND action_url='/messages/?message=$attachment_message'") == 1 ]]
sql -e "UPDATE messages SET deleted_at=NOW() WHERE id=$attachment_message"
[[ $(curl -sS -b "$smoke_root/recipient.cookie" -o /dev/null -w '%{http_code}' "$base$attachment_url") == 404 ]]

curl -fsS -b "$smoke_root/sender.cookie" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' \
    --data '{"typing":true}' "$base/messages/api/typing.php" >/dev/null
history_page 'typing=1' | php -r '$data=json_decode(stream_get_contents(STDIN), true); exit($data["typing"][0]["id"] === 1 ? 0 : 1);'
curl -fsS -b "$smoke_root/other.cookie" "$base/messages/api/fetch.php?typing=1" |
    php -r '$data=json_decode(stream_get_contents(STDIN), true); exit($data["typing"]===[] ? 0 : 1);'

php "$smoke_root/cron/notification-reminders.php"
php "$smoke_root/cron/notification-reminders.php"
[[ $(sql -Nse 'SELECT COUNT(*) FROM scheduled_deliveries') == 1 ]]
[[ $(sql -Nse "SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='calendar'") == 1 ]]
printf 'Authenticated chat, history, attachments, typing, push queue and reminder checks passed.\n'

# Use the legacy attachment column width to exercise the seven-photo save and reopen flow.
base64 -d >"$smoke_root/fixture.png" <<'PNG'
iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGN0YShnYGBgYmBgYGBgAAAIuwC/5jqQFAAAAABJRU5ErkJggg==
PNG
photo_fields=()
for photo in {1..7}; do
    photo_fields+=(-F "images[]=@$smoke_root/fixture.png;type=image/png")
done
body=$'## Materials\n- Paint\n- [ ] Measure the fence\n1. Order supplies'
curl -fsS -b "$smoke_root/sender.cookie" -F "csrf_token=$csrf" -F action=create_note \
    -F 'title=Fixture photo note' -F "body=$body" -F photo_count=7 "${photo_fields[@]}" "$base/notes/" >"$smoke_root/note.json"
read -r note_id version image < <(php -r '
    $note = json_decode(file_get_contents($argv[1]), true)["note"] ?? null;
    if (!$note || count($note["images"]) !== 7) exit(1);
    echo "$note[id] $note[version] {$note["images"][0]}\n";
' "$smoke_root/note.json")
[[ $(curl -fsS -b "$smoke_root/recipient.cookie" -o /dev/null -w '%{http_code}' "$base$image") == 200 ]]
[[ $(curl -sS -b "$smoke_root/other.cookie" -o /dev/null -w '%{http_code}' "$base$image") == 404 ]]
curl -fsS -b "$smoke_root/sender.cookie" --data-urlencode "csrf_token=$csrf" \
    --data-urlencode action=update_note --data-urlencode "note_id=$note_id" --data-urlencode "version=$version" \
    --data-urlencode 'title=Fixture photo note edited' --data-urlencode "body=$body" \
    --data-urlencode "remove_images[]=$image" "$base/notes/" >"$smoke_root/note-edit.json"
php -r '
    $note = json_decode(file_get_contents($argv[1]), true)["note"] ?? null;
    exit($note && count($note["images"]) === 6 && $note["body"] === $argv[2] ? 0 : 1);
' "$smoke_root/note-edit.json" "$body"
[[ $(curl -sS -b "$smoke_root/sender.cookie" -o /dev/null -w '%{http_code}' "$base$image") == 404 ]]
printf 'Notes photo save, family access and removal checks passed.\n'

# Track the same phone through an authenticated Wake, a poor fix and a crossing.
# The fixture has no Firebase credentials, so the active service's authenticated
# pending-request endpoint is the delivery fallback exercised here.
phone=5b092341-4e7f-4ced-b750-0c385aca6321
sql -e "UPDATE fcm_tokens SET installation_id='$phone', wake_capability='silent_ready',
    wake_session_hash=(SELECT session_token FROM sessions WHERE user_id=2 ORDER BY id DESC LIMIT 1)
    WHERE user_id=2"
sql -e "INSERT INTO tracking_geofences (family_id,name,center_lat,center_lng,radius_m)
    VALUES (1,'Fixture Home',-26.2006,28.0,50);
    INSERT INTO tracking_alert_rules (family_id) VALUES (1);"

track() {
    local recorded_ms
    recorded_ms=$(php -r 'echo (int) floor(microtime(true) * 1000);')
    curl -fsS -b "$smoke_root/recipient.cookie" -H 'Content-Type: application/json'         --data "{\"lat\":$1,\"lng\":28.0,\"accuracy_m\":$2,\"recorded_at_ms\":$recorded_ms,\"platform\":\"android\",\"device_id\":\"$phone\",\"client_event_id\":\"$3\"}"         "$base/tracking/api/location.php"
}
track -26.2000 10 initial | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["data"]["advanced_current"] ? 0 : 1);'
curl -fsS -b "$smoke_root/sender.cookie" -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json'     --data '{"mode":"manual","client_request_id":"ee545bd0-4425-437f-a14d-ffa485b0aca5"}'     "$base/tracking/api/wake_devices.php" >"$smoke_root/wake.json"
wave=$(php -r '$r=json_decode(file_get_contents($argv[1]), true); echo $r["data"]["request_id"] ?? "";' "$smoke_root/wake.json")
[[ $wave =~ ^[a-f0-9-]{36}$ ]]
curl -fsS -b "$smoke_root/recipient.cookie" "$base/tracking/api/wake_pending.php?device_id=$phone" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); exit(($r["data"]["request_id"] ?? null) === $argv[1] ? 0 : 1);' "$wave"
[[ $(curl -sS -b "$smoke_root/other.cookie" -o /dev/null -w '%{http_code}' "$base/tracking/api/wake_status.php?request_id=$wave") == 404 ]]

track -26.2050 700 coarse | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["data"]["selection"] === "hold_coarse" ? 0 : 1);'
curl -fsS -b "$smoke_root/sender.cookie" "$base/tracking/api/current.php" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); foreach ($r["data"] as $m) if ($m["user_id"]===2) exit(abs($m["lat"] + 26.2) < 0.00001 && $m["accuracy_m"]===10 ? 0 : 1); exit(1);'
track -26.2006 10 entered | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["data"]["advanced_current"] ? 0 : 1);'
curl -fsS -b "$smoke_root/sender.cookie" "$base/tracking/api/wake_status.php?request_id=$wave" |
    php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["data"]["targets"][0]["state"] === "refreshed" ? 0 : 1);'
[[ $(sql -Nse "SELECT COUNT(*) FROM notifications WHERE type='tracking' AND message LIKE 'Fixture recipient entered Fixture Home at %'") == 1 ]]
event=$(sql -Nse "SELECT id FROM tracking_events WHERE event_type='enter_geofence'")
[[ $event =~ ^[0-9]+$ ]]
curl -fsS -b "$smoke_root/sender.cookie" "$base/tracking/app/events.php?event=$event" >"$smoke_root/location-event.html"
grep -q 'Fixture Home' "$smoke_root/location-event.html"
[[ $(curl -sS -b "$smoke_root/other.cookie" -o /dev/null -w '%{http_code}' "$base/tracking/app/events.php?event=$event") == 404 ]]
printf 'Authenticated silent Wake, pin quality and named geofence event checks passed.\n'
