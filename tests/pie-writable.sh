#!/usr/bin/env bash
# PIE elevates with sudo when PHP's extension directory isn't writable, but refuses to in a non-interactive
# shell (CI): make it writable instead (Linux; Homebrew's and Windows' PHP already are).
set -euo pipefail
if [ "$(uname -s)" = Linux ]; then
  sudo chmod a+w "$(php -r 'echo ini_get("extension_dir");')"
fi
