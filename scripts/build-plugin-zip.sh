#!/usr/bin/env bash
# Builds dist/local_claudeapi.zip with a single root folder "claudeapi" (as Moodle's installer requires).
set -euo pipefail
cd "$(dirname "$0")/../moodle-plugin"
mkdir -p ../dist
rm -f ../dist/local_claudeapi.zip
zip -q -r -D -X ../dist/local_claudeapi.zip claudeapi -x "*.DS_Store" "__MACOSX/*"
echo "dist/local_claudeapi.zip"
