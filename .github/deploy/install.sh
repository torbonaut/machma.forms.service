#!/bin/sh
# Runs on the server:  ssh host "sh -s -- <deploy-path>" < install.sh
# Expects <deploy-path>/.release.tar (uploaded right before). Needs only sh, tar, mkdir, mv, rm –
# no rsync. Each top-level folder is swapped as a whole, so files removed from the repo disappear
# and the site is never half-extracted for longer than a rename.
# Never touched: secrets.php, var/ (SQLite) and config/ (compiled.php from the config repo).
set -eu

cd "$1"
test -f .release.tar || { echo "keine .release.tar in $1" >&2; exit 1; }

rm -rf .release .old
mkdir .release .old
tar -xf .release.tar -C .release
rm -f .release.tar

for path in .release/* .release/.[!.]*; do
  [ -e "$path" ] || continue
  item=${path#.release/}
  case "$item" in
    secrets.php|var|config) continue ;;   # server-owned, never replaced
  esac
  if [ -e "$item" ]; then mv "$item" .old/; fi
  mv "$path" "$item"
done

mkdir -p var config
chmod 700 var
rm -rf .release .old
echo "deploy ok: $(date '+%Y-%m-%d %H:%M:%S')"
