#!/usr/bin/env bash
# The native build dependencies of the CI runners: libclang (bindgen), OpenSSL. Prints the cargo features
# to build with to $GITHUB_OUTPUT (features=...).
set -euo pipefail
features=""
case "$(uname -s)" in
  Linux)
    sudo apt-get update -qq
    sudo apt-get install -y -qq libclang-dev libssl-dev pkg-config >/dev/null
    ;;
  Darwin)
    echo "OPENSSL_DIR=$(brew --prefix openssl@3)" >> "$GITHUB_ENV"
    echo "LIBCLANG_PATH=$(xcode-select -p)/Toolchains/XcodeDefault.xctoolchain/usr/lib" >> "$GITHUB_ENV"
    ;;
  MINGW*|MSYS*|CYGWIN*)
    # OpenSSL built from source and linked statically (the runner has Strawberry Perl; NASM for its asm).
    choco install -y --no-progress nasm >/dev/null
    echo "C:\\Program Files\\NASM" >> "$GITHUB_PATH"
    echo "LIBCLANG_PATH=C:\\Program Files\\LLVM\\bin" >> "$GITHUB_ENV"
    features="vendored-openssl"
    ;;
esac
echo "features=$features" >> "${GITHUB_OUTPUT:-/dev/null}"
