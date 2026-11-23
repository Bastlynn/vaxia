#!/bin/bash
# Downloads Drupal 7 core + the latest 7.x release of each contrib project into tests/.build/.
# Usage: tests/docker/fetch-contrib.sh [project[:version] ...]   (no args = full list in contrib.txt)
# contrib.txt lines are "project" (latest 7.x release) or "project version" (pinned, e.g. "date 7.x-2.14").
set -e
here=$(cd "$(dirname "$0")" && pwd)
build="$here/../.build"
mkdir -p "$build/contrib" "$build/themes" "$build/dl"
projects=("$@")
if [ ${#projects[@]} -eq 0 ]; then
  projects=(drupal $(grep -v '^#' "$here/contrib.txt" "$here/themes.txt" -h | tr ' ' ':'))
fi
for spec in "${projects[@]}"; do
  p=${spec%%:*}; ver=""; [ "$spec" != "$p" ] && ver=${spec#*:}
  if [ "$p" = drupal ] && [ -d "$build/drupal" ]; then continue; fi
  dest="$build/contrib"
  grep -qE "^$p( |\$)" "$here/themes.txt" && dest="$build/themes"
  if [ "$p" != drupal ] && [ -d "$dest/$p" ]; then continue; fi
  xml=$(curl -fsSL "https://updates.drupal.org/release-history/$p/7.x") || { echo "MISSING $p"; continue; }
  if [ -n "$ver" ]; then
    url="https://ftp.drupal.org/files/projects/$p-$ver.tar.gz"
  else
    url=$(echo "$xml" | grep -o '<download_link>[^<]*</download_link>' | head -1 | sed 's/<[^>]*>//g')
  fi
  if [ -z "$url" ]; then echo "NORELEASE $p"; continue; fi
  echo "$p <- $url"
  curl -fsSL "$url" -o "$build/dl/$p.tar.gz"
  if [ "$p" = drupal ]; then
    tar -xzf "$build/dl/$p.tar.gz" -C "$build" && mv "$build"/drupal-7.* "$build/drupal"
  else
    tar -xzf "$build/dl/$p.tar.gz" -C "$dest"
  fi
done
