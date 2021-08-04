#!/usr/bin/env bash

CONTENTRELEASE=$1
WORKER=$2

while true; do
  ./flow nodeRendering:renderWorker $CONTENTRELEASE $WORKER
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

