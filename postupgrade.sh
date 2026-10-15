#!/bin/bash
set -eu
umask 077
. "$(dirname -- "$0")/bin/lifecycle-common.sh"
a2l_init "$@"
a2l_finish_upgrade
printf '<OK> Update abgeschlossen; Einstellungen und Gerätesitzung sind erhalten.\n'
