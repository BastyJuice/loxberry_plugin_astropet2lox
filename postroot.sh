#!/bin/bash
set -eu
umask 077
. "$(dirname -- "$0")/bin/lifecycle-common.sh"
a2l_init "$@"
a2l_install_service
