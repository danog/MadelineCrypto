#!/usr/bin/env bash
# Zips the built extension under PIE's pre-packaged binary names (php/pie: PrePackagedBinaryAssetName,
# and the Windows naming of php-windows-builder), and prints the archives to $GITHUB_OUTPUT (assets=...).
#   Linux/macOS: php_madeline_crypto-{version}_php{X.Y}-{arch}-{os}-{libc}-nts.zip, holding madeline_crypto.so
#   Windows:     php_madeline_crypto-{version}-{X.Y}-nts-{vs16|vs17}-{arch}.zip, holding a DLL of the same name
set -euo pipefail
version="$1"
cd "$(dirname "$0")/.."
lib="$(tests/library-path.sh)"
php_version="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
[ "$(php -r 'echo PHP_ZTS ? 1 : 0;')" = 0 ] || { echo "Only NTS PHP is supported" >&2; exit 1; }
case "$(php -r 'echo php_uname("m");')" in
  x86_64|amd64|AMD64) arch=x86_64 ;;
  arm64|aarch64|ARM64) arch=arm64 ;;
  *) echo "Unsupported architecture" >&2; exit 1 ;;
esac
mkdir -p dist
rm -rf dist/stage && mkdir dist/stage
case "$(uname -s)" in
  Linux|Darwin)
    if [ "$(uname -s)" = Linux ]; then os=linux; libc=glibc; else os=darwin; libc=bsdlibc; fi
    name="php_madeline_crypto-${version}_php${php_version}-${arch}-${os}-${libc}-nts"
    cp "$lib" dist/stage/madeline_crypto.so
    (cd dist/stage && zip -q "../$name.zip" madeline_crypto.so)
    ;;
  MINGW*|MSYS*|CYGWIN*)
    # "PHP Extension Build => API20240924,NTS,VS17"
    compiler="$(php -i | sed -n 's/^PHP Extension Build => .*,\(VS[0-9]*\)$/\1/p' | tr 'A-Z' 'a-z')"
    [ -n "$compiler" ] || { echo "Can't read the compiler from php -i" >&2; exit 1; }
    name="php_madeline_crypto-${version}-${php_version}-nts-${compiler}-${arch}"
    cp "$(cygpath -u "$lib")" "dist/stage/$name.dll"
    (cd dist/stage && 7z a -bd -tzip "../$name.zip" "$name.dll" >/dev/null)
    ;;
esac
echo "Packaged dist/$name.zip"
echo "assets=dist/$name.zip" >> "${GITHUB_OUTPUT:-/dev/null}"
