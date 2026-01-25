\# CRON SETUP



Add this line to your crontab:



```bash

\# Weather notifications (runs every minute, checks who needs notification)

\* \* \* \* \* /usr/bin/php /path/to/your/app/cron/weather-notifications.php >> /path/to/your/app/logs/weather-cron.log 2>\&1



\# Notification cleanup (runs daily at 3 AM)

0 3 \* \* \* /usr/bin/php /path/to/your/app/cron/notification-cleanup.php >> /path/to/your/app/logs/cleanup-cron.log 2>\&1

