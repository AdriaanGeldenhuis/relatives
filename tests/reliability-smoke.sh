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
sql -e "UPDATE messages SET content='Edited fixture message', edited_at=NOW() WHERE id=$id"
delta recipient 1 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit($r["messages"][0]["content"] === "Edited fixture message" ? 0 : 1);'
sql -e "UPDATE messages SET deleted_at=NOW() WHERE id=$id"
delta recipient 2 | php -r '$r=json_decode(stream_get_contents(STDIN), true); exit(count($r["removed"]) === 1 ? 0 : 1);'

php "$smoke_root/cron/notification-reminders.php"
php "$smoke_root/cron/notification-reminders.php"
[[ $(sql -Nse 'SELECT COUNT(*) FROM scheduled_deliveries') == 1 ]]
[[ $(sql -Nse "SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='calendar'") == 1 ]]
printf 'Authenticated chat, push queue and reminder checks passed.\n'
