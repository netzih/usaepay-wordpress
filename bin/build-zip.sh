#!/usr/bin/env bash
# Build the installable plugin zip: tracked files only, production Composer
# dependencies (the bundled library copied in, not symlinked), no tests.
# Usage: bin/build-zip.sh [output-dir]   -> <output-dir>/usaepay-payments-<version>.zip
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-"$root/build"}
version=$(sed -n 's/^ \* Version: *//p' "$root/usaepay-payments.php" | head -1)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/usaepay-payments" "$out"
git -C "$root" archive HEAD | tar -x -C "$stage/usaepay-payments"
(
  cd "$stage/usaepay-payments"
  COMPOSER_MIRROR_PATH_REPOS=1 composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
  # composer.lock records the path repository as a symlink; ship a copy.
  lib=vendor/chabadrichmond/usaepay-php
  if [ -L "$lib" ]; then rm "$lib" && cp -R lib/usaepay-php "$lib"; fi
  rm -rf tests phpunit.xml.dist bin lib/usaepay-php/tests lib/usaepay-php/phpunit.xml.dist vendor/chabadrichmond/usaepay-php/tests vendor/chabadrichmond/usaepay-php/phpunit.xml.dist
  find . -name '.DS_Store' -delete
)
if find "$stage" -type l | grep -q .; then
  echo "symlinks left in the build:" >&2; find "$stage" -type l >&2; exit 1
fi
zip="$out/usaepay-payments-$version.zip"
rm -f "$zip"
(cd "$stage" && zip -qr "$zip" usaepay-payments)
echo "$zip"
