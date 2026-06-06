#!/usr/bin/env bash
#
# backup-server.sh — ONE-TIME pre-deploy backup of the live HitchStream files.
#
# Pulls a complete, faithful copy of the two server directories the v2 deploy
# will touch — the child theme and the Cloudflare plugin — into a local,
# timestamped folder that mirrors the server's layout exactly.
#
#   backups/<timestamp>/wp-content/themes/celebration-child/...
#   backups/<timestamp>/wp-content/plugins/HitchStream_Cloudflare/...
#
# This is READ-ONLY against the server. It makes no changes to the live site.
#
# It backs up the ENTIRE theme and plugin directories — not just the handful of
# files the deploy overwrites — so you have a full, restorable snapshot. The
# layout it produces is identical to what deploy.sh creates, so rollback.sh can
# restore straight from it:   ./rollback.sh <timestamp>
#
# Usage:
#   ./backup-server.sh             Pull the backup (default).
#   ./backup-server.sh --dry-run   Show what WOULD be pulled, transfer nothing.
#   ./backup-server.sh --help      Show this help.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Configuration (kept in sync with deploy.sh / rollback.sh) ────────────────

SSH_ALIAS="hitchstream-deploy"
REMOTE_THEME="/home/admin_hitchstream/public_html/wp-content/themes/celebration-child"
REMOTE_PLUGIN="/home/admin_hitchstream/public_html/wp-content/plugins/HitchStream_Cloudflare"
BACKUP_ROOT="backups"

# ── Argument parsing ─────────────────────────────────────────────────────────

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run|-n) DRY_RUN=1 ;;
        --help|-h)
            sed -n '/^# Usage:/,/^# ──/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg" >&2
            echo "Run: ./backup-server.sh --help" >&2
            exit 1
            ;;
    esac
done

# ── Helpers ──────────────────────────────────────────────────────────────────

color_red()    { printf '\033[0;31m%s\033[0m' "$*"; }
color_green()  { printf '\033[0;32m%s\033[0m' "$*"; }
color_yellow() { printf '\033[1;33m%s\033[0m' "$*"; }
color_cyan()   { printf '\033[0;36m%s\033[0m' "$*"; }
step() { echo; color_cyan "==> $*"; echo; }
note() { echo "    $*"; }
abort() { echo; color_red "ABORT: $*"; echo; exit 1; }

# ── Pre-flight ───────────────────────────────────────────────────────────────

step "Pre-flight checks"

if ! ssh -o ConnectTimeout=10 -o BatchMode=yes "$SSH_ALIAS" "true" 2>/dev/null; then
    abort "SSH connection to '$SSH_ALIAS' failed. Check ~/.ssh/config and your key."
fi
note "$(color_green '✓') SSH connection ($SSH_ALIAS) works"

# Confirm both source directories exist on the server before we start.
if ! ssh "$SSH_ALIAS" "test -d '$REMOTE_THEME'" 2>/dev/null; then
    abort "Remote theme dir not found: $REMOTE_THEME"
fi
if ! ssh "$SSH_ALIAS" "test -d '$REMOTE_PLUGIN'" 2>/dev/null; then
    abort "Remote plugin dir not found: $REMOTE_PLUGIN"
fi
note "$(color_green '✓') Remote theme dir exists"
note "$(color_green '✓') Remote plugin dir exists"

if [[ $DRY_RUN -eq 1 ]]; then
    note "$(color_yellow 'Mode: DRY RUN') (nothing will be transferred)"
else
    note "Mode: LIVE backup (read-only on the server)"
fi

# ── Paths ────────────────────────────────────────────────────────────────────

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/${TIMESTAMP}"
BACKUP_THEME="${BACKUP_DIR}/wp-content/themes/celebration-child"
BACKUP_PLUGIN="${BACKUP_DIR}/wp-content/plugins/HitchStream_Cloudflare"

if [[ -e "$BACKUP_DIR" ]]; then
    abort "Backup target already exists: $BACKUP_DIR (refusing to overwrite)."
fi

# ── Dry run: show what would transfer, then stop ─────────────────────────────

if [[ $DRY_RUN -eq 1 ]]; then
    step "Dry-run: files that WOULD be pulled into ${BACKUP_DIR}/"
    note "Theme  → ${BACKUP_THEME}/"
    rsync -azn --itemize-changes "$SSH_ALIAS:${REMOTE_THEME}/" "/tmp/__hs_bkup_probe_theme/" || true
    note "Plugin → ${BACKUP_PLUGIN}/"
    rsync -azn --itemize-changes "$SSH_ALIAS:${REMOTE_PLUGIN}/" "/tmp/__hs_bkup_probe_plugin/" || true
    echo
    color_yellow "✓ DRY RUN COMPLETE. Re-run without --dry-run to actually back up."
    echo
    exit 0
fi

# ── Step 1: Pull a faithful copy of both directories ─────────────────────────

step "Pulling full server snapshot to ${BACKUP_DIR}/"

mkdir -p "$BACKUP_THEME" "$BACKUP_PLUGIN"

# -a archive (perms/times/structure), -z compress in transit. No excludes:
# we want an exact, faithful copy of everything on the server.
rsync -az --stats "$SSH_ALIAS:${REMOTE_THEME}/"  "${BACKUP_THEME}/"
rsync -az --stats "$SSH_ALIAS:${REMOTE_PLUGIN}/" "${BACKUP_PLUGIN}/"

THEME_FILES=$(find "$BACKUP_THEME" -type f | wc -l | tr -d ' ')
PLUGIN_FILES=$(find "$BACKUP_PLUGIN" -type f | wc -l | tr -d ' ')
BACKUP_SIZE=$(du -sh "$BACKUP_DIR" | awk '{print $1}')

note "$(color_green '✓') Pulled theme:  ${THEME_FILES} files"
note "$(color_green '✓') Pulled plugin: ${PLUGIN_FILES} files"
note "    Total on disk: ${BACKUP_SIZE}"

if [[ "$THEME_FILES" -eq 0 || "$PLUGIN_FILES" -eq 0 ]]; then
    abort "A directory came back empty. Backup is NOT trustworthy — investigate before deploying."
fi

# ── Step 2: Save a server-side inventory + checksum manifest ─────────────────
# These let us prove later that the local copy matches the server byte-for-byte.

step "Generating verification manifests"

# Server-side checksums (relative paths), pulled down so we can verify locally.
# Pick sha256sum (standard on Linux); fall back to `shasum -a 256` if absent.
# $SUM stays unquoted in -exec so "shasum -a 256" expands to separate args.
REMOTE_CKSUM='SUM=$(command -v sha256sum || echo "shasum -a 256"); find . -type f -exec $SUM {} +'

ssh "$SSH_ALIAS" "cd '$REMOTE_THEME'  && $REMOTE_CKSUM" \
    > "${BACKUP_DIR}/MANIFEST-theme-sha256.txt"  2>/dev/null || true
ssh "$SSH_ALIAS" "cd '$REMOTE_PLUGIN' && $REMOTE_CKSUM" \
    > "${BACKUP_DIR}/MANIFEST-plugin-sha256.txt" 2>/dev/null || true

# Human-readable inventory of exactly what's on the server right now.
{
    echo "# Server inventory captured at ${TIMESTAMP}"
    echo "# Host alias: ${SSH_ALIAS}"
    echo
    echo "## THEME: ${REMOTE_THEME}"
    ssh "$SSH_ALIAS" "cd '$REMOTE_THEME'  && find . -type f | sort" 2>/dev/null
    echo
    echo "## PLUGIN: ${REMOTE_PLUGIN}"
    ssh "$SSH_ALIAS" "cd '$REMOTE_PLUGIN' && find . -type f | sort" 2>/dev/null
} > "${BACKUP_DIR}/SERVER-INVENTORY.txt"

note "$(color_green '✓') SERVER-INVENTORY.txt written"
note "$(color_green '✓') Checksum manifests written"

# ── Step 3: Verify the local copy against the server checksums ────────────────

step "Verifying local backup against server checksums"

verify_dir() {
    local label="$1" dir="$2" manifest="$3"
    if [[ ! -s "$manifest" ]]; then
        note "$(color_yellow '!') ${label}: server checksums unavailable — skipped (size-only copy still valid)"
        VERIFY_SKIPPED=1
        return 0
    fi
    local failed
    # shasum -c reads "<hash>  ./path" lines and checks each file in $dir.
    failed=$( cd "$dir" && shasum -a 256 -c "$manifest" 2>/dev/null | grep -c 'FAILED' || true )
    if [[ "$failed" -eq 0 ]]; then
        note "$(color_green '✓') ${label}: every file matches the server (SHA-256)"
    else
        note "$(color_red '✗') ${label}: ${failed} file(s) FAILED verification"
        VERIFY_OK=0
    fi
}

VERIFY_OK=1
VERIFY_SKIPPED=0
verify_dir "Theme"  "$BACKUP_THEME"  "$(pwd)/${BACKUP_DIR}/MANIFEST-theme-sha256.txt"
verify_dir "Plugin" "$BACKUP_PLUGIN" "$(pwd)/${BACKUP_DIR}/MANIFEST-plugin-sha256.txt"

# ── Step 4: Drop a README inside the backup ──────────────────────────────────

cat > "${BACKUP_DIR}/BACKUP-INFO.txt" <<EOF
HitchStream — pre-deploy server backup
======================================

Created : ${TIMESTAMP}
By      : backup-server.sh (one-time manual pre-deploy snapshot)
Source  : ${SSH_ALIAS}
            ${REMOTE_THEME}
            ${REMOTE_PLUGIN}

This is a COMPLETE copy of both directories as they existed on the live
server immediately before the v2 deploy — not just the files the deploy
changes. Layout mirrors the server exactly under wp-content/.

What the deploy will do to these files:
  REPLACED (overwritten in place):
    - themes/celebration-child/HitchStream-Player.php
    - themes/celebration-child/functions.php
    - themes/celebration-child/endpoints/CloudFlareEP.php
    - plugins/HitchStream_Cloudflare/HitchStream-Cloudflare.php
    - plugins/HitchStream_Cloudflare/js/hscf-admin.js
  DELETED:
    - themes/celebration-child/js/HSPlayerElement.js
    - themes/celebration-child/js/old/   (cloudflare_player.js)
  Everything else in this backup is left untouched by the deploy.

To restore the server from this backup:
    ./rollback.sh ${TIMESTAMP}

Verification:
    MANIFEST-theme-sha256.txt  — server-side SHA-256 of every theme file
    MANIFEST-plugin-sha256.txt — server-side SHA-256 of every plugin file
    SERVER-INVENTORY.txt       — full file listing as it was on the server

NOTE: This is a FILE backup only. It does NOT include the WordPress
database. Back up the database separately (it holds the HSCF_* options
and, after deploy, the wp_hs_webhook_log table).
EOF

note "$(color_green '✓') BACKUP-INFO.txt written"

# ── Done ─────────────────────────────────────────────────────────────────────

step "Backup complete"
note "Location: $(color_cyan "${BACKUP_DIR}/")"
note "Restore with: $(color_cyan "./rollback.sh ${TIMESTAMP}")"
echo
if [[ "$VERIFY_OK" -eq 0 ]]; then
    color_red "✗ BACKUP VERIFICATION HAD FAILURES. Do NOT deploy until resolved."
elif [[ "$VERIFY_SKIPPED" -eq 1 ]]; then
    color_yellow "✓ Backup pulled, but checksum verification was SKIPPED. Spot-check before deploying."
else
    color_green "✓ SOLID BACKUP VERIFIED (SHA-256). Safe to proceed with the deploy."
fi
echo
color_yellow "Reminder: this is files only — back up the WordPress database separately."
echo
