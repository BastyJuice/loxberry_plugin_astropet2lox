#!/bin/bash
set -eu
umask 077
. "$(dirname -- "$0")/bin/lifecycle-common.sh"
a2l_init "$@"
# Validate before the installer can purge any existing configuration.
a2l_php
# Runs before preupgrade and the installer purge, so backups are not concurrent.
a2l_stop
