#!/usr/bin/env bash
#
# deploy.sh — HitchStream Player v2 deployment script
#
# Usage:
#   ./deploy.sh                 Run full deploy (backup + confirmation + upload).
#   ./deploy.sh --dry-run       Show what would happen, no transfers, no deletes.
#   ./deploy.sh --help          Show this help.
#
# Behavior on each run, in order:
#   1. Pre-flight: verify SSH connection works, confirm local deploy/ exists.
#   2. Pull current server state into backups/<timestamp>/ as a rollback snapshot.
#   3. Show summary; prompt for confirmation (unless CONFIRM_BEFORE_UPLOAD=false).
#   4. rsync the contents of deploy/wp-content/ to the server.
#   5. Remove two known-stale paths on the server:
#        - js/HSPlayerElement.js (legacy player file)
#        - js/old/                (legacy Cloudflare player directory)
#   6. Print where the backup was saved.
#
# Authentication: uses the SSH host alias defined in ~/.ssh/config.
# No password lives in this script. Set up SSH keys per the instructions in
# the deploy walkthrough before running.
#
# Edit the constants at the top to match your environment.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────────────

# SSH alias from ~/.ssh/config. Hostname/user/key all looked up there.
SSH_ALIAS="hitchstream-deploy"

# Absolute paths on the server. End with no trailing slash.
REMOTE_THEME="/home/admin_hitchstream/public_html/wp-content/themes/celebration-child"
REMOTE_PLUGIN="/home/admin_hitchstream/public_html/wp-content/plugins/HitchStream_Cloudflare"

# Local source for the upload. Must contain themes/celebration-child and
# plugins/HitchStream_Cloudflare. Built by rebuild step in deployfiles.md.
LOCAL_DEPLOY="deploy/wp-content"

# Where backups go. Timestamped subfolders are created automatically.
BACKUP_ROOT="backups"

# Show the y/n prompt before uploading. Set to "false" to skip the prompt
# once you're comfortable with the script (still keeps backups + dry-run).
CONFIRM_BEFORE_UPLOAD="${CONFIRM_BEFORE_UPLOAD:-true}"

# Files/directories on the server to delete after upload. These are the
# legacy paths the rebuild made obsolete. Adjust over time as needed.
SERVER_CLEANUP_PATHS=(
    "${REMOTE_THEME}/js/HSPlayerElement.js"
    "${REMOTE_THEME}/js/old"
    # Defunct "HitchStream Controls" page template — /control is now served by
    # the plugin's ControlPage (2026-07-15).
    "${REMOTE_THEME}/hitchstreamcontrols.php"
)

# ── Argument parsing ─────────────────────────────────────────────────────────

DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --dry-run|-n)
            DRY_RUN=1
            ;;
        --help|-h)
            sed -n '/^# Usage:/,/^# ──/p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "Unknown argument: $arg" >&2
            echo "Run: ./deploy.sh --help" >&2
            exit 1
            ;;
    esac
done

# ── Helpers ──────────────────────────────────────────────────────────────────

color_red()    { printf '\033[0;31m%s\033[0m' "$*"; }
color_green()  { printf '\033[0;32m%s\033[0m' "$*"; }
color_yellow() { printf '\033[1;33m%s\033[0m' "$*"; }
color_cyan()   { printf '\033[0;36m%s\033[0m' "$*"; }

step() {
    echo
    color_cyan "==> $*"; echo
}

note() {
    echo "    $*"
}

abort() {
    echo
    color_red "ABORT: $*"; echo
    exit 1
}

# ── Pre-flight ───────────────────────────────────────────────────────────────

step "Pre-flight checks"

if [[ ! -d "$LOCAL_DEPLOY/themes/celebration-child" ]]; then
    abort "Missing $LOCAL_DEPLOY/themes/celebration-child. Rebuild deploy/ first (see deployfiles.md)."
fi
if [[ ! -d "$LOCAL_DEPLOY/plugins/HitchStream_Cloudflare" ]]; then
    abort "Missing $LOCAL_DEPLOY/plugins/HitchStream_Cloudflare. Rebuild deploy/ first (see deployfiles.md)."
fi

if ! ssh -o ConnectTimeout=10 -o BatchMode=yes "$SSH_ALIAS" "true" 2>/dev/null; then
    abort "SSH connection to '$SSH_ALIAS' failed. Check ~/.ssh/config and your key."
fi

note "$(color_green '✓') Local deploy/ directory present"
note "$(color_green '✓') SSH connection ($SSH_ALIAS) works"

if [[ $DRY_RUN -eq 1 ]]; then
    note "$(color_yellow 'Mode: DRY RUN') (no files will be transferred or deleted)"
else
    note "Mode: LIVE deploy"
fi

# ── Pre-flight: deploy/ freshness ────────────────────────────────────────────
# The #1 way to ship a broken site is a STALE deploy/ — old file contents, or a
# new file (e.g. a new css) that was never copied in. This refuses to deploy
# unless deploy/ exactly matches the current source. Rebuild deploy/ (and commit
# new files first — the rebuild only includes committed/tracked changes) if this
# fails. See deployfiles.md "If deploy/ ever gets out of sync".

step "Verifying deploy/ matches current source"

FRESH_ISSUES=0

# (a) Files in deploy/ whose contents differ from the repo source.
while IFS= read -r df; do
    case "$df" in
        */plugins/HitchStream_Cloudflare/*) repo="HitchStream_Cloudflare/${df##*/plugins/HitchStream_Cloudflare/}" ;;
        */themes/celebration-child/*)        repo="celebration-child/${df##*/themes/celebration-child/}" ;;
        *) continue ;;
    esac
    if [[ ! -f "$repo" ]] || ! cmp -s "$df" "$repo"; then
        note "$(color_red '✗') out of date in deploy/: ${repo}"
        FRESH_ISSUES=1
    fi
done < <(find "$LOCAL_DEPLOY" -type f ! -name '.DS_Store')

# (b) Source files changed/added since the baseline that are MISSING from deploy/.
if command -v git >/dev/null 2>&1 && git rev-parse --git-dir >/dev/null 2>&1 && git cat-file -e 41d35f0 2>/dev/null; then
    while IFS= read -r src; do
        [[ -z "$src" || ! -f "$src" ]] && continue
        # Match the rebuild script's exclusions: tests never ship.
        [[ "$src" == *"__tests__"* || "$src" == *"/tests/"* ]] && continue
        case "$src" in
            HitchStream_Cloudflare/*) dpath="${LOCAL_DEPLOY}/plugins/${src}" ;;
            celebration-child/*)      dpath="${LOCAL_DEPLOY}/themes/${src}" ;;
            *) continue ;;
        esac
        if [[ ! -f "$dpath" ]]; then
            note "$(color_red '✗') missing from deploy/: ${src}"
            FRESH_ISSUES=1
        fi
    done < <( { git diff --name-only 41d35f0 -- celebration-child/ HitchStream_Cloudflare/ 2>/dev/null
                git ls-files --others --exclude-standard -- celebration-child/ HitchStream_Cloudflare/ 2>/dev/null; } | sort -u )

    # Loud-but-not-fatal warning if the working tree has uncommitted changes.
    if [[ -n "$(git status --porcelain -- celebration-child/ HitchStream_Cloudflare/ 2>/dev/null)" ]]; then
        note "$(color_yellow '!') You have uncommitted changes in the deployed dirs — deploy ships committed code only via deploy/. Commit + rebuild deploy/ if you meant to include them."
    fi
fi

if [[ $FRESH_ISSUES -eq 1 ]]; then
    abort "deploy/ is out of sync with your source (see ✗ above). Rebuild deploy/ (deployfiles.md), commit any new files first, then re-run."
fi
note "$(color_green '✓') deploy/ matches current source"

# ── Timestamp & backup paths ─────────────────────────────────────────────────

TIMESTAMP="$(date +%Y-%m-%d_%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/${TIMESTAMP}"
BACKUP_THEME="${BACKUP_DIR}/wp-content/themes/celebration-child"
BACKUP_PLUGIN="${BACKUP_DIR}/wp-content/plugins/HitchStream_Cloudflare"

# ── Step 1: Pull server snapshot ─────────────────────────────────────────────

step "Pulling server snapshot to ${BACKUP_DIR}/"

if [[ $DRY_RUN -eq 0 ]]; then
    mkdir -p "$BACKUP_THEME" "$BACKUP_PLUGIN"

    rsync -az --stats --exclude='.DS_Store' \
        "$SSH_ALIAS:${REMOTE_THEME}/" "${BACKUP_THEME}/"
    rsync -az --stats --exclude='.DS_Store' \
        "$SSH_ALIAS:${REMOTE_PLUGIN}/" "${BACKUP_PLUGIN}/"

    THEME_FILES=$(find "$BACKUP_THEME" -type f 2>/dev/null | wc -l | tr -d ' ')
    PLUGIN_FILES=$(find "$BACKUP_PLUGIN" -type f 2>/dev/null | wc -l | tr -d ' ')
    BACKUP_SIZE=$(du -sh "$BACKUP_DIR" 2>/dev/null | awk '{print $1}')

    note "$(color_green '✓') Backup saved at ${BACKUP_DIR}/"
    note "    Theme:  ${THEME_FILES} files"
    note "    Plugin: ${PLUGIN_FILES} files"
    note "    Total:  ${BACKUP_SIZE}"
else
    note "Dry-run: would back up server theme + plugin to ${BACKUP_DIR}/"
fi

# ── Step 2: Confirmation prompt ──────────────────────────────────────────────

if [[ "$CONFIRM_BEFORE_UPLOAD" == "true" && $DRY_RUN -eq 0 ]]; then
    UPLOAD_THEME=$(find "$LOCAL_DEPLOY/themes/celebration-child" -type f | wc -l | tr -d ' ')
    UPLOAD_PLUGIN=$(find "$LOCAL_DEPLOY/plugins/HitchStream_Cloudflare" -type f | wc -l | tr -d ' ')

    step "Ready to deploy"
    note "Will upload ${UPLOAD_THEME} files into ${REMOTE_THEME}/"
    note "Will upload ${UPLOAD_PLUGIN} files into ${REMOTE_PLUGIN}/"
    for path in "${SERVER_CLEANUP_PATHS[@]}"; do
        note "Will delete on server: ${path}"
    done
    echo
    printf "Type %s to proceed (anything else aborts): " "$(color_yellow yes)"
    read -r CONFIRM
    if [[ "$CONFIRM" != "yes" ]]; then
        abort "Cancelled. Backup at ${BACKUP_DIR}/ is preserved."
    fi
fi

# ── Step 3: Upload ───────────────────────────────────────────────────────────

step "Uploading new files"

# Upload the PLUGIN FIRST, then the theme. The theme's functions.php requires a
# plugin file (src/HS/CloudflareClient.php); if the theme lands first there is a
# window where it references a not-yet-present plugin file. Plugin-first closes
# that window. (functions.php also has an is_readable guard as a backstop.)
if [[ $DRY_RUN -eq 1 ]]; then
    rsync -rlvzn --no-perms --no-times --stats --itemize-changes --exclude='.DS_Store' \
        "$LOCAL_DEPLOY/plugins/HitchStream_Cloudflare/" \
        "$SSH_ALIAS:${REMOTE_PLUGIN}/"
    rsync -rlvzn --no-perms --no-times --stats --itemize-changes --exclude='.DS_Store' \
        "$LOCAL_DEPLOY/themes/celebration-child/" \
        "$SSH_ALIAS:${REMOTE_THEME}/"
    note "$(color_yellow 'Dry-run: above is what WOULD be transferred.')"
else
    # --no-perms --no-times: the target dirs are owned by another user (we're in
    # the group, not the owner), so rsync can't set perms/times on them. We only
    # need the file CONTENTS to land; new files inherit group via setgid + umask.
    rsync -rlvz --no-perms --no-times --stats --exclude='.DS_Store' \
        "$LOCAL_DEPLOY/plugins/HitchStream_Cloudflare/" \
        "$SSH_ALIAS:${REMOTE_PLUGIN}/"
    rsync -rlvz --no-perms --no-times --stats --exclude='.DS_Store' \
        "$LOCAL_DEPLOY/themes/celebration-child/" \
        "$SSH_ALIAS:${REMOTE_THEME}/"
    note "$(color_green '✓') Upload complete"
fi

# ── Step 4: Cleanup stale files ──────────────────────────────────────────────

step "Cleaning up legacy files on server"

CLEANUP_CMD=""
for path in "${SERVER_CLEANUP_PATHS[@]}"; do
    CLEANUP_CMD+="rm -rf '${path}'; "
done

if [[ $DRY_RUN -eq 1 ]]; then
    note "Dry-run: would run on server: ${CLEANUP_CMD}"
else
    ssh "$SSH_ALIAS" "$CLEANUP_CMD" || true
    note "$(color_green '✓') Cleanup done"
fi

# ── Done ─────────────────────────────────────────────────────────────────────

step "Deploy complete"

if [[ $DRY_RUN -eq 0 ]]; then
    note "Backup of pre-deploy state: $(color_cyan "${BACKUP_DIR}/")"
    note "If anything is broken on the live site, run: $(color_cyan "./rollback.sh ${TIMESTAMP}")"
    echo
    color_green "✓ DEPLOYED. Verify the live site now."
    echo
else
    echo
    color_yellow "✓ DRY RUN COMPLETE. Re-run without --dry-run to actually deploy."
    echo
fi
