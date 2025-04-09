#!/bin/sh
# Background jobs for the demo. On a real server use system cron instead:
#   * * * * * wp --path=/var/www/html action-scheduler run --quiet
set -u
cd /var/www/html

while true; do
	wp action-scheduler run --batch-size=25 --quiet >/dev/null 2>&1 || true
	wp cron event run --due-now --quiet >/dev/null 2>&1 || true
	sleep 10
done
