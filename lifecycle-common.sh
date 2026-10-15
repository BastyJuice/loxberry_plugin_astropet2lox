#!/bin/bash
# Installed helper for the LoxBerry lifecycle scripts. No account data is logged.

a2l_die() {
    printf '<ERROR> %s\n' "$1" >&2
    exit 2
}

a2l_absolute() {
    case "$1" in
        /*) ;;
        *) a2l_die 'Ein LoxBerry-Verzeichnispfad ist ungültig.' ;;
    esac
    case "$1" in
        *$'\n'*|*$'\r'*|*$'\t'*|*/../*|*/./*|*/..|*/.)
            a2l_die 'Ein LoxBerry-Verzeichnispfad ist ungültig.' ;;
    esac
}

a2l_init() {
    A2L_NAME=${2:-}
    A2L_FOLDER=${3:-}
    [[ "$A2L_NAME" =~ ^astropet2lox[0-9]*$ ]] || a2l_die 'Ungültiger Pluginname.'
    [ "${#A2L_NAME}" -le 64 ] || a2l_die 'Ungültiger Pluginname.'
    [[ "$A2L_FOLDER" =~ ^astropet2lox[0-9]*$ ]] || a2l_die 'Ungültiges Pluginverzeichnis.'
    [ "${#A2L_FOLDER}" -le 64 ] || a2l_die 'Ungültiges Pluginverzeichnis.'
    A2L_HOME=${LBHOMEDIR:-${5:-}}
    a2l_absolute "$A2L_HOME"
    A2L_HOME=${A2L_HOME%/}
    [ -n "$A2L_HOME" ] || a2l_die 'LoxBerry-Basisverzeichnis fehlt.'
    A2L_CONFIG="${LBPCONFIG:-$A2L_HOME/config/plugins}/$A2L_FOLDER"
    A2L_DATA="${LBPDATA:-$A2L_HOME/data/plugins}/$A2L_FOLDER"
    A2L_BIN="${LBPBIN:-$A2L_HOME/bin/plugins}/$A2L_FOLDER"
    a2l_absolute "$A2L_CONFIG"
    a2l_absolute "$A2L_DATA"
    a2l_absolute "$A2L_BIN"
    A2L_TEMP=${6:-}
    A2L_UNIT="astropet2lox-$A2L_FOLDER.service"
    A2L_UNIT_PATH="/etc/systemd/system/$A2L_UNIT"
}

a2l_systemd() {
    command -v systemctl >/dev/null 2>&1 || a2l_die 'systemd wird für AstroPet2Lox benötigt.'
    systemctl show --property=Version --value >/dev/null 2>&1 \
        || a2l_die 'Der systemd-Dienstmanager ist nicht erreichbar.'
}

a2l_php() {
    A2L_PHP=''
    local candidate binary
    for candidate in php php7.4 php8.4 php8.3 php8.2 php8.1 php8.0; do
        binary=$(command -v "$candidate" 2>/dev/null || true)
        [ -n "$binary" ] && [ -f "$binary" ] && [ -x "$binary" ] || continue
        a2l_absolute "$binary"
        if "$binary" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 70400 && PHP_INT_SIZE === 8 && extension_loaded("curl") && extension_loaded("openssl") && extension_loaded("zlib") && extension_loaded("pcntl") ? 0 : 1);' >/dev/null 2>&1; then
            A2L_PHP=$binary
            return
        fi
    done
    a2l_die 'Das vorhandene PHP ab 7.4 (64 Bit) mit cURL, OpenSSL, zlib und pcntl fehlt.'
}

a2l_unit_state() {
    local properties line key value status=0
    A2L_LOAD=''; A2L_ACTIVE=''; A2L_MAIN_PID=''; A2L_CONTROL_PID=''
    properties=$(systemctl show "$A2L_UNIT" --property=LoadState --property=ActiveState \
        --property=MainPID --property=ControlPID 2>/dev/null) || status=$?
    while IFS='=' read -r key value; do
        case "$key" in
            LoadState) A2L_LOAD=$value ;;
            ActiveState) A2L_ACTIVE=$value ;;
            MainPID) A2L_MAIN_PID=$value ;;
            ControlPID) A2L_CONTROL_PID=$value ;;
        esac
    done <<< "$properties"
    [[ "$A2L_LOAD" =~ ^[a-z][a-z-]*$ ]] && [[ "$A2L_ACTIVE" =~ ^[a-z][a-z-]*$ ]] \
        && [[ "$A2L_MAIN_PID" =~ ^[0-9]{1,10}$ ]] && [[ "$A2L_CONTROL_PID" =~ ^[0-9]{1,10}$ ]] \
        || a2l_die 'Der Zustand des Plugin-Dienstes konnte nicht sicher geprüft werden.'
    [ "$status" -eq 0 ] || [ "$A2L_LOAD" = 'not-found' ] \
        || a2l_die 'Der Zustand des Plugin-Dienstes konnte nicht sicher geprüft werden.'
}

a2l_unit_inactive() {
    { [ "$A2L_ACTIVE" = inactive ] || [ "$A2L_ACTIVE" = failed ]; } \
        && [ "$A2L_MAIN_PID" = 0 ] && [ "$A2L_CONTROL_PID" = 0 ]
}

a2l_stop() {
    a2l_systemd
    a2l_unit_state
    case "$A2L_LOAD" in
        bad-setting|error|not-found)
            if a2l_unit_inactive; then return 0; fi ;;
    esac
    systemctl stop "$A2L_UNIT" >/dev/null 2>&1 \
        || a2l_die 'Der laufende Plugin-Dienst konnte nicht beendet werden.'
    a2l_unit_state
    a2l_unit_inactive || a2l_die 'Der Plugin-Dienst läuft weiterhin; das Update wurde vor dem Ersetzen der Dateien abgebrochen.'
}

a2l_backup_path() {
    a2l_absolute "$A2L_TEMP"
    [ -d "$A2L_TEMP" ] || a2l_die 'Installationsverzeichnis fehlt.'
    A2L_BACKUP="$A2L_TEMP/.astropet2lox-backup-$A2L_FOLDER"
}

a2l_backup() {
    a2l_backup_path
    [ ! -e "$A2L_BACKUP" ] || a2l_die 'Eine unerwartete Updatesicherung ist vorhanden.'
    mkdir -m 700 -- "$A2L_BACKUP" || a2l_die 'Updatesicherung konnte nicht angelegt werden.'
    if [ -d "$A2L_CONFIG" ]; then
        cp -a -- "$A2L_CONFIG" "$A2L_BACKUP/config" || a2l_die 'Konfiguration konnte nicht gesichert werden.'
    fi
    if [ -d "$A2L_DATA" ]; then
        cp -a -- "$A2L_DATA" "$A2L_BACKUP/data" || a2l_die 'Gerätesitzung konnte nicht gesichert werden.'
    fi
    printf '1\n' > "$A2L_BACKUP/ready"
    chmod 600 "$A2L_BACKUP/ready"
    printf '<OK> Konfiguration und Gerätesitzung sind für das Update geschützt.\n'
}

a2l_private() {
    mkdir -p -- "$A2L_CONFIG" "$A2L_DATA/state"
    # Apache, CLI and the worker run as loxberry on the official LoxBerry image.
    find "$A2L_CONFIG" "$A2L_DATA" -type d -exec chmod 700 {} +
    find "$A2L_CONFIG" "$A2L_DATA" -type f -exec chmod 600 {} +
}

a2l_restore() {
    a2l_backup_path
    if [ -f "$A2L_BACKUP/ready" ]; then
        if [ -d "$A2L_BACKUP/config" ]; then
            mkdir -p -- "$A2L_CONFIG"
            cp -a -- "$A2L_BACKUP/config/." "$A2L_CONFIG/" || a2l_die 'Konfiguration konnte nicht wiederhergestellt werden.'
        fi
        if [ -d "$A2L_BACKUP/data" ]; then
            mkdir -p -- "$A2L_DATA"
            cp -a -- "$A2L_BACKUP/data/." "$A2L_DATA/" || a2l_die 'Gerätesitzung konnte nicht wiederhergestellt werden.'
        fi
    fi
    a2l_private
}

a2l_finish_upgrade() {
    a2l_restore
    if [ -f "$A2L_BACKUP/ready" ]; then
        rm -rf -- "$A2L_BACKUP"
    fi
}

# Quote one systemd argument; percent specifiers are literal in plugin paths.
a2l_quote() {
    local value=$1
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    value=${value//%/%%}
    printf '"%s"' "$value"
}

# WorkingDirectory is a raw scalar path, unlike the word lists ExecStart/Environment.
# systemd's path parser does not remove surrounding quotes here.
a2l_unit_path() {
    local value=$1
    a2l_absolute "$value"
    value=${value//%/%%}
    printf '%s' "$value"
}

a2l_service_diagnostics() {
    local properties key value
    properties=$(systemctl show "$A2L_UNIT" --property=LoadState --property=ActiveState --property=SubState \
        --property=Result --property=ExecMainCode --property=ExecMainStatus --property=MainPID 2>/dev/null) || true
    while IFS='=' read -r key value; do
        case "$key" in
            LoadState|ActiveState|SubState|Result|ExecMainCode|ExecMainStatus|MainPID)
                if [[ "$value" =~ ^[a-zA-Z0-9_-]{1,64}$ ]]; then printf '<INFO> Dienst %s=%s\n' "$key" "$value"; fi ;;
        esac
    done <<< "$properties"
}

a2l_install_service() {
    a2l_systemd
    a2l_php
    [ -f "$A2L_BIN/worker.php" ] || a2l_die 'Die Worker-Datei fehlt.'
    a2l_backup_path
    local unit_temp unit_dir
    unit_dir=$(mktemp -d "$A2L_TEMP/.astropet2lox-unit.XXXXXX") \
        || a2l_die 'Die Dienstdatei konnte nicht vorbereitet werden.'
    unit_temp="$unit_dir/$A2L_UNIT"
    {
        printf '[Unit]\nDescription=AstroPet2Lox device data bridge\nAfter=network-online.target\nWants=network-online.target\n\n'
        printf '[Service]\nType=exec\nUser=loxberry\nGroup=loxberry\nUMask=0077\n'
        printf 'Environment=%s\n' "$(a2l_quote "LBHOMEDIR=$A2L_HOME")"
        printf 'WorkingDirectory=%s\n' "$(a2l_unit_path "$A2L_BIN")"
        printf 'ExecStart=%s -d display_errors=0 -d zend.exception_ignore_args=1 -d %s %s --daemon\n' \
            "$(a2l_quote "$A2L_PHP")" "$(a2l_quote "include_path=.:$A2L_HOME/libs/phplib")" "$(a2l_quote "$A2L_BIN/worker.php")"
        printf 'Restart=on-failure\nRestartSec=10\nKillSignal=SIGTERM\nTimeoutStopSec=15\nKillMode=control-group\n'
        printf 'NoNewPrivileges=true\nPrivateTmp=true\nStandardOutput=journal\nStandardError=journal\n\n'
        printf '[Install]\nWantedBy=multi-user.target\n'
    } > "$unit_temp"
    if ! command -v systemd-analyze >/dev/null 2>&1; then
        rm -rf -- "$unit_dir"
        a2l_die 'systemd-analyze fehlt; die Dienstdatei konnte nicht geprüft werden.'
    fi
    if ! SYSTEMD_COLORS=0 systemd-analyze verify --man=no --recursive-errors=no "$unit_temp"; then
        rm -rf -- "$unit_dir"
        a2l_die 'Die systemd-Prüfung der Plugin-Dienstdatei ist fehlgeschlagen.'
    fi
    if ! install -o root -g root -m 644 -- "$unit_temp" "$A2L_UNIT_PATH"; then
        rm -rf -- "$unit_dir"
        a2l_die 'Die Plugin-Dienstdatei konnte nicht installiert werden.'
    fi
    rm -rf -- "$unit_dir"
    systemctl daemon-reload >/dev/null 2>&1 || a2l_die 'Die Dienstkonfiguration konnte nicht neu geladen werden.'
    if ! systemctl enable --now "$A2L_UNIT"; then
        a2l_service_diagnostics
        a2l_die 'Der Plugin-Dienst konnte nicht aktiviert werden.'
    fi
    printf '<OK> AstroPet2Lox-Dienst installiert; Kontoabrufe werden durch die Plugin-Einstellung aktiviert.\n'
}

a2l_uninstall_service() {
    a2l_systemd
    local loaded
    loaded=$(systemctl show "$A2L_UNIT" --property=LoadState --value 2>/dev/null) \
        || a2l_die 'Der Plugin-Dienst konnte nicht geprüft werden.'
    if [ "$loaded" != 'not-found' ] && [ -n "$loaded" ]; then
        systemctl disable --now "$A2L_UNIT" >/dev/null 2>&1 \
            || a2l_die 'Der Plugin-Dienst konnte nicht deaktiviert werden.'
    fi
    a2l_cleanup_gateway
    rm -f -- "$A2L_UNIT_PATH"
    systemctl daemon-reload >/dev/null 2>&1 || a2l_die 'Die Dienstkonfiguration konnte nicht neu geladen werden.'
    printf '<OK> AstroPet2Lox-Dienst entfernt.\n'
}

# Optional, file-only cleanup after the worker stopped and before LoxBerry purges DATA.
a2l_cleanup_gateway() {
    [ -f "$A2L_BIN/gateway-cleanup.php" ] || return 0
    local candidate binary cleanup_php=''
    for candidate in php php7.4 php8.4 php8.3 php8.2 php8.1 php8.0; do
        binary=$(command -v "$candidate" 2>/dev/null || true)
        [ -n "$binary" ] && [ -f "$binary" ] && [ -x "$binary" ] || continue
        case "$binary" in /*) ;; *) continue ;; esac
        case "$binary" in *$'\n'*|*$'\r'*|*$'\t'*) continue ;; esac
        # No network/worker extensions are needed for removing a saved subscription.
        if "$binary" -r 'exit(PHP_SAPI === "cli" && PHP_VERSION_ID >= 70400 && PHP_INT_SIZE === 8 ? 0 : 1);' >/dev/null 2>&1; then
            cleanup_php=$binary
            break
        fi
    done
    if [ -z "$cleanup_php" ]; then
        printf '<WARNING> Eigene MQTT-Weiterleitung konnte nicht geprüft werden: Das vorhandene PHP ab 7.4 (64 Bit) fehlt.\n'
        return 0
    fi
    if ! LBHOMEDIR="$A2L_HOME" "$cleanup_php" -d display_errors=0 -d zend.exception_ignore_args=1 \
        -d "include_path=.:$A2L_HOME/libs/phplib" "$A2L_BIN/gateway-cleanup.php" >/dev/null 2>&1; then
        printf '<WARNING> Eigene MQTT-Weiterleitung bei Bedarf manuell prüfen; die Deinstallation wird fortgesetzt.\n'
    fi
    return 0
}
