#!/usr/bin/env bash
# Syntax-check every PHP file and run the framework-free test suite.
# Run from the repository root: .github/scripts/check.sh
set -euo pipefail

failed=0
count=0
while IFS= read -r -d '' file; do
  count=$((count + 1))
  if ! output="$(php -l "$file" 2>&1)"; then
    echo "::error file=${file}::PHP syntax error"
    echo "$output"
    failed=1
  fi
done < <(find . -name '*.php' -not -path './.git/*' -not -path './vendor/*' -print0)

echo "Linted $count PHP files"
if [ "$count" -eq 0 ]; then
  echo "::error::No PHP files found, refusing to continue"
  exit 1
fi
if [ "$failed" -ne 0 ]; then
  exit 1
fi

# plugin.json must be valid and declare what the panel reads. A missing api_version makes the
# panel's plugin list show a warning.
php -r '
$p = json_decode(file_get_contents("plugin.json"), true, flags: JSON_THROW_ON_ERROR);
foreach (["id", "name", "version", "namespace", "class"] as $key) {
    if (!is_string($p[$key] ?? null) || $p[$key] === "") { fwrite(STDERR, "::error file=plugin.json::missing $key\n"); exit(1); }
}
if (!is_int($p["api_version"] ?? null)) { fwrite(STDERR, "::error file=plugin.json::api_version must be an integer\n"); exit(1); }
if (!preg_match("/^\d+\.\d+\.\d+$/", $p["version"])) { fwrite(STDERR, "::error file=plugin.json::version must look like 1.2.3, the release workflow tags vX.Y.Z\n"); exit(1); }
$build = file_get_contents(".github/scripts/build-package.sh");
if (!str_contains($build, "PLUGIN_ID=\"{$p["id"]}\"")) { fwrite(STDERR, "::error file=.github/scripts/build-package.sh::PLUGIN_ID does not match the plugin id {$p["id"]}\n"); exit(1); }
echo "plugin.json OK (api_version {$p["api_version"]})\n";
'

php tests/run.php .
