#!/usr/bin/env bash
#
# rollback.sh — restore the live site from a backup folder
#
# Usage:
#   ./rollback.sh                       List available backups.
#   ./rollback.sh <timestamp>           Roll back to that backup.
#   ./rollback.sh <timestamp> --dry-run Show what would change, don't transfer.
#
# Example:
#   ./rollback.sh 2026-04-28_173042
#
# This script does the inverse of deploy.sh: pushes the contents of
# backups/<timestamp>/wp-content/ back onto the server. NO local backup is
# made (the rollback target IS the backup). For safety, requires you to
# type 'rollback' to confirm.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

SSH_ALIAS="hitchstream-deploy"
REMOTE_THEME="/home/admin_hitchstream/public_html/wp-content/themes/celebration-child"
REMOTE_PLUGIN="/home/admin_hitchstream/public_html/wp-content/plugins/HitchStream_Cloudflare"
BACKUP_ROOT="backups"

color_red()    { printf '\033[0;31m%s\033[0m' "$*"; }
color_green()  { printf '\033[0;32m%s\033[0m' "$*"; }
color_yellow() { printf '\033[1;33m%s\033[0m' "$*"; }
color_cyan()   { printf '\033[0;36m%s\033[0m' "$*"; }

# ── List mode ────────────────────────────────────────────────────────────────

if [[ $# -eq 0 ]]; then
    echo "Usage: ./rollback.sh <backup-timestamp> [--dry-run]"
    echo
    echo "Available backups in ${BACKUP_ROOT}/:"
    if [[ -d "$BACKUP_ROOT" ]]; then
        ls -1 "$BACKUP_ROOT" 2>/dev/null | sort -r | head -20 | sed 's/^/  /'
    else
        echo "  (no backups directory found)"
    fi
    echo
    echo "Example: ./rollback.sh 2026-04-28_173042"
    exit 1
fi

# ── Argument parsing ─────────────────────────────────────────────────────────

TIMESTAMP="$1"
DRY_RUN=0
if [[ "${2:-}" == "--dry-run" || "${2:-}" == "-n" ]]; then
    DRY_RUN=1
fi

BACKUP_DIR="${BACKUP_ROOT}/${TIMESTAMP}"
BACKUP_THEME="${BACKUP_DIR}/wp-content/themes/celebration-child"
BACKUP_PLUGIN="${BACKUP_DIR}/wp-content/plugins/HitchStream_Cloudflare"

if [[ ! -d "$BACKUP_THEME" || ! -d "$BACKUP_PLUGIN" ]]; then
    echo
    color_red "Backup not found or incomplete: ${BACKUP_DIR}"; echo
    echo
    echo "Expected:"
    echo "  ${BACKUP_THEME}/"
    echo "  ${BACKUP_PLUGIN}/"
    exit 1
fi

# ── Pre-flight ───────────────────────────────────────────────────────────────

echo
color_cyan "==> Pre-flight"; echo
if ! ssh -o ConnectTimeout=10 -o BatchMode=yes "$SSH_ALIAS" "true" 2>/dev/null; then
    color_red "ABORT: SSH connection to '$SSH_ALIAS' failed."; echo
    exit 1
fi
echo "    $(color_green '✓') SSH ok"

if [[ $DRY_RUN -eq 1 ]]; then
    echo "    Mode: $(color_yellow 'DRY RUN')"
else
    echo "    Mode: LIVE rollback"
fi

THEME_FILES=$(find "$BACKUP_THEME" -type f | wc -l | tr -d ' ')
PLUGIN_FILES=$(find "$BACKUP_PLUGIN" -type f | wc -l | tr -d ' ')

# ── Confirmation ─────────────────────────────────────────────────────────────

echo
color_cyan "==> About to ROLL BACK the server"; echo
echo "    Source: ${BACKUP_DIR}/"
echo "    Theme:  ${THEME_FILES} files → ${REMOTE_THEME}/"
echo "    Plugin: ${PLUGIN_FILES} files → ${REMOTE_PLUGIN}/"
echo
echo "    $(color_yellow 'This will overwrite current files on the live server')"
echo "    $(color_yellow 'with the snapshot taken at:') ${TIMESTAMP}"
echo

if [[ $DRY_RUN -eq 0 ]]; then
    printf "Type %s to proceed (anything else cancels): " "$(color_red rollback)"
    read -r CONFIRM
    if [[ "$CONFIRM" != "rollback" ]]; then
        echo
        echo "Cancelled. Server unchanged."
        exit 1
    fi
fi

# ── Restore ──────────────────────────────────────────────────────────────────

echo
color_cyan "==> Restoring files"; echo

if [[ $DRY_RUN -eq 1 ]]; then
    rsync -rlvzn --no-perms --no-times --stats --itemize-changes --exclude='.DS_Store' \
        "${BACKUP_THEME}/" "$SSH_ALIAS:${REMOTE_THEME}/"
    rsync -rlvzn --no-perms --no-times --stats --itemize-changes --exclude='.DS_Store' \
        "${BACKUP_PLUGIN}/" "$SSH_ALIAS:${REMOTE_PLUGIN}/"
    echo
    color_yellow "✓ DRY RUN complete. Re-run without --dry-run to actually roll back."
    echo
else
    # Safety: snapshot the CURRENT server state before overwriting it, so a
    # rollback to the wrong timestamp is itself recoverable.
    SNAP="${BACKUP_ROOT}/pre-rollback-$(date +%Y-%m-%d_%H%M%S)"
    echo "    Snapshotting current server state to ${SNAP}/ first..."
    mkdir -p "${SNAP}/wp-content/themes/celebration-child" "${SNAP}/wp-content/plugins/HitchStream_Cloudflare"
    rsync -az --exclude='.DS_Store' "$SSH_ALIAS:${REMOTE_THEME}/"  "${SNAP}/wp-content/themes/celebration-child/"
    rsync -az --exclude='.DS_Store' "$SSH_ALIAS:${REMOTE_PLUGIN}/" "${SNAP}/wp-content/plugins/HitchStream_Cloudflare/"

    rsync -rlvz --no-perms --no-times --stats --exclude='.DS_Store' "${BACKUP_THEME}/"  "$SSH_ALIAS:${REMOTE_THEME}/"
    rsync -rlvz --no-perms --no-times --stats --exclude='.DS_Store' "${BACKUP_PLUGIN}/" "$SSH_ALIAS:${REMOTE_PLUGIN}/"
    echo
    color_green "✓ ROLLBACK COMPLETE. Server is at the state from ${TIMESTAMP}."
    echo "    (Pre-rollback snapshot of what was just overwritten: ${SNAP}/)"
    echo "    Verify the live site now."
    echo
fi
