#!/usr/bin/env bash

CONTENTRELEASE=$1
WORKER=$2

# taken from https://newbedev.com/forward-sigterm-to-child-in-bash
prep_term()
{
    unset term_child_pid
    unset term_kill_needed
    trap 'handle_term' TERM INT
}

handle_term()
{
    if [ "${term_child_pid}" ]; then
        kill -TERM "${term_child_pid}" 2>/dev/null
    else
        term_kill_needed="yes"
    fi
}

wait_term()
{
    term_child_pid=$!
    if [ "${term_kill_needed}" ]; then
        kill -TERM "${term_child_pid}" 2>/dev/null
    fi
    wait ${term_child_pid} 2>/dev/null
    trap - TERM INT
    wait ${term_child_pid} 2>/dev/null
}


while true; do
  prep_term
  ./flow nodeRendering:renderWorker $CONTENTRELEASE $WORKER &
  wait_term
  RETURNCODE=$?
  if [ $RETURNCODE -ne 193 ]
  then
     # another return code than 193 given; so we pass it on to the outside.
     echo "Received return code $RETURNCODE, exiting"
     exit $RETURNCODE
  fi
  # return code 193 means "restart"
  echo "Restarting render worker."
done

