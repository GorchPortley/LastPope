#!/bin/sh
# Start-up hook of the Flarum image. serversideup's entrypoint sources every /etc/entrypoint.d/*.sh in
# order, as the container user (www-data), before nginx and PHP-FPM start - or before the command of a
# helper container such as the scheduler. All the logic is in start.php (see the comment there).
php /usr/local/lib/lastpope/start.php
