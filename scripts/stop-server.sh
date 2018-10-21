#!/usr/bin/env bash

if [[ $# -eq 0 ]] ; then
    echo 'Missing required .pid file argument.'
    exit 1
fi

stop_server () {
    if [ -e $1 ]; then
        PID=$(cat $1);
        # Getting smtp-sink's pid by matching second column (ppid) of ps output clumsily
        #NODEPID=$(ps xao pid,ppid,pgid,sid,comm | awk -v pid="$PID" '$2==pid{print $1}');
        NODEPID=$(ps xao pid,ppid,pgid,comm | awk -v pid="$PID" '$2==pid{print $1}');
        echo PID=$PID;
        echo NODEPID=$NODEPID;
        # Killing nodejs pid that we spawned (hopefully)
        if [ ! -z "$NODEPID" ]; then
            kill $NODEPID || true;
        fi;
        # Killing juts the PID is not enough sometimes, nodejs server still lingers on (at least on Windows)
        if [ ! -z "$PID" ]; then
            kill $PID || true;
        fi;
# TODO/FIXME: this manages to kill too much or something, whole recipe fails :/
#       if [ ! -z "$$PGID" ]; then
#           kill -- -$$PGID || true;
#       fi;
        rm -rf $1 || true;
    fi;
}

stop_server "$1"
