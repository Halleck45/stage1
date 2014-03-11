#!/bin/bash

source /usr/local/lib/stage1.sh

/usr/sbin/sshd

# @todo move that to container ENV (maybe from Project's config)
export APP_ROOT=/app
cd $APP_ROOT

STAGE1_STAGE='run'

if [ -x /usr/local/bin/stage1_image_init_run ]; then
    stage1_image_init_run
fi

/usr/local/bin/yuhao_run

tail -F /var/log/syslog $LOG_FILES