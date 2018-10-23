#!/usr/bin/env bash

if [[ $# -eq 0 ]] ; then
    echo 'Missing required .pid file argument.'
    exit 1
fi

stop_server () {
    if [ -e $1 ]; then
        PID=$(cat $1);
        echo PID=$PID;
        # Killing juts the PID is not enough sometimes, nodejs server still lingers on (at least on Windows)
        if [ ! -z "$PID" ]; then
            kill $PID || true;
        fi;
        rm -rf $1 || true;
    fi;
}

stop_server "$1"
