#!/usr/bin/env bash
set -euo pipefail

# Lumora Gallery — On-Demand Thumbnails helper
#
# Deletes every thumb_* file in one album folder in a single pass — a full
# migration of that folder to on-demand thumbnail generation, rather than
# delete-every-other-thumb.sh's partial/cautious approach. Only files
# starting with "thumb_" are ever touched — originals are never considered
# or deleted. Anything deleted here can be recreated later via Admin ->
# Tools -> Regenerate Missing Thumbnails, as long as the original photo is
# still in place.
#
# With --recursive, <folder> can be a top-level directory (e.g. albums/
# itself) containing many album subfolders — every thumb_* file found
# anywhere under it is deleted, across every album at once.
#
# Requires the On-Demand Thumbnails plugin to be enabled and the
# albums/.htaccess rewrite rule from the plugin's README to already be in
# place — otherwise deleted thumbnails will just 404 instead of being
# generated on request. Both can now be set up from Admin -> On-Demand
# Thumbnails instead of by hand — see README.md.
#
# This script is a thin wrapper: argument parsing and the confirmation
# prompt happen here, but the actual file listing/sorting/deletion is done
# by delete-thumbs-cli.php (OnDemandThumbnailService::deleteThumbnails()),
# the same code the Admin -> On-Demand Thumbnails settings page uses (LG-068).
#
# Usage:
#   delete-all-thumbs.sh <folder> [--dry-run] [--yes] [--recursive]
#
#   <folder>      Path to an album folder containing thumb_* files, or (with
#                 --recursive) a top-level directory containing many albums.
#   --dry-run     Print what would be deleted without deleting anything.
#   --yes         Skip the confirmation prompt (for use in scripts).
#   --recursive   Delete thumb_* files in every subfolder under <folder>,
#                 not just <folder> itself.

usage() {
    echo "Usage: $0 <folder> [--dry-run] [--yes] [--recursive]" >&2
    exit 1
}

[ $# -ge 1 ] || usage

folder=""
dry_run=0
assume_yes=0
recursive=0

for arg in "$@"; do
    case "$arg" in
        --dry-run)   dry_run=1 ;;
        --yes|-y)    assume_yes=1 ;;
        --recursive) recursive=1 ;;
        -*)          usage ;;
        *)           folder="$arg" ;;
    esac
done

[ -n "$folder" ] || usage
[ -d "$folder" ] || { echo "Not a directory: $folder" >&2; exit 1; }

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cli="$script_dir/delete-thumbs-cli.php"

cli_args=("$folder")
[ "$recursive" -eq 1 ] && cli_args+=(--recursive)

# Always preview first, regardless of --dry-run, so the confirmation prompt
# below can show what's about to happen.
php "$cli" "${cli_args[@]}" --dry-run

if [ "$dry_run" -eq 1 ]; then
    exit 0
fi

if [ "$assume_yes" -ne 1 ]; then
    read -r -p "Delete these files? Type 'yes' to confirm: " confirm
    [ "$confirm" = "yes" ] || { echo "Aborted — nothing deleted."; exit 1; }
fi

php "$cli" "${cli_args[@]}"
