#!/usr/bin/env bash
# The extension cargo built: .so on Linux, .dylib on macOS, .dll on Windows.
set -euo pipefail
dir="$(cd "$(dirname "$0")/.." && pwd)/target/release"
for f in libmadeline_crypto.so libmadeline_crypto.dylib madeline_crypto.dll; do
  if [ -f "$dir/$f" ]; then
    case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) cygpath -w "$dir/$f" ;; *) echo "$dir/$f" ;; esac
    exit 0
  fi
done
echo "No built extension in $dir" >&2
exit 1
