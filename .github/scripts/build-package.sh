#!/usr/bin/env bash
# Build the installable plugin zip from a release tag's tree.
#
# Usage: TAG=v1.2.3 VERSION=1.2.3 .github/scripts/build-package.sh
# Run inside a clone that has the tag. Writes modrinth-integration.zip and
# modrinth-integration.zip.sha256 to $OUT_DIR (default: the current directory).
#
# The zip is built with `git archive` from the tag, never from the working tree
# or the default branch, so commits that landed after the tag are not included.
# PluginService accepts plugin.json at the archive root or one directory deep and
# installs it under plugins/<id>, so everything lives below a top-level
# modrinth-integration/ directory (the plugin id).
set -euo pipefail

: "${TAG:?TAG is required}"
: "${VERSION:?VERSION is required}"
PLUGIN_ID="modrinth-integration"
OUT_DIR="$(cd "${OUT_DIR:-$PWD}" && pwd)"

if ! git rev-parse --verify --quiet "refs/tags/${TAG}^{commit}" > /dev/null; then
  echo "::error::Tag ${TAG} does not exist in this clone"
  exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
stage="$work/$PLUGIN_ID"
mkdir -p "$stage"

git archive --format=tar "refs/tags/${TAG}" | tar -x -C "$stage"

# Repository-only files that must not ship.
rm -rf "$stage/.github" "$stage/tests"
find "$stage" -name '.git*' -prune -exec rm -rf {} +

# The panel decides whether an update is available by comparing the update feed's
# version with the plugin.json of the installed copy. The tag may have been created
# before the metadata bump landed on the default branch, so stamp the release version
# into the packaged manifest. Otherwise the panel would offer this update forever.
jq --arg version "$VERSION" '.version = $version' "$stage/plugin.json" > "$work/plugin.json"
mv "$work/plugin.json" "$stage/plugin.json"

rm -f "$OUT_DIR/$PLUGIN_ID.zip" "$OUT_DIR/$PLUGIN_ID.zip.sha256"
(
  cd "$work"
  find "$PLUGIN_ID" -print | LC_ALL=C sort | zip -X -q "$OUT_DIR/$PLUGIN_ID.zip" -@
)

# Verify the archive layout before anything is published.
entries="$(unzip -Z1 "$OUT_DIR/$PLUGIN_ID.zip")"

if grep -vE "^${PLUGIN_ID}/" <<< "$entries" | grep -q .; then
  echo "::error::Archive has entries outside ${PLUGIN_ID}/"
  exit 1
fi
if grep -E "(^|/)\.git|^${PLUGIN_ID}/(tests|\.github)(/|$)" <<< "$entries" | grep -q .; then
  echo "::error::Archive contains excluded paths"
  exit 1
fi
grep -qx "${PLUGIN_ID}/plugin.json" <<< "$entries" || { echo "::error::Archive has no ${PLUGIN_ID}/plugin.json"; exit 1; }

packaged_id="$(unzip -p "$OUT_DIR/$PLUGIN_ID.zip" "$PLUGIN_ID/plugin.json" | jq -r '.id')"
packaged_version="$(unzip -p "$OUT_DIR/$PLUGIN_ID.zip" "$PLUGIN_ID/plugin.json" | jq -r '.version')"
test "$packaged_id" = "$PLUGIN_ID" || { echo "::error::Packaged plugin id is ${packaged_id}"; exit 1; }
test "$packaged_version" = "$VERSION" || { echo "::error::Packaged version is ${packaged_version}"; exit 1; }

(cd "$OUT_DIR" && sha256sum "$PLUGIN_ID.zip" > "$PLUGIN_ID.zip.sha256")
echo "Built $OUT_DIR/$PLUGIN_ID.zip from $TAG ($(wc -l <<< "$entries") entries)"
cat "$OUT_DIR/$PLUGIN_ID.zip.sha256"
