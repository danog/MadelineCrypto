#!/usr/bin/env bash
# The native build dependencies of the CI runners: libclang (bindgen), OpenSSL. Prints the cargo features
# to build with to $GITHUB_OUTPUT (features=...).
set -euo pipefail
features=""
case "$(uname -s)" in
  Linux)
    sudo apt-get update -qq
    sudo apt-get install -y -qq libclang-dev libssl-dev pkg-config >/dev/null
    # bindgen loads the newest libclang it finds, but parses the clang headers of the LLVM libclang-dev
    # installed: on Ubuntu 22.04 (several LLVMs) a newer libclang failed on LLVM 14's emmintrin.h. Use
    # that LLVM's libclang.
    v="$(dpkg-query -W -f='${Depends}' libclang-dev | sed -n 's/.*libclang-\([0-9][0-9]*\)-dev.*/\1/p')"
    if [ -n "$v" ] && [ -d "/usr/lib/llvm-$v/lib" ]; then
      echo "LIBCLANG_PATH=/usr/lib/llvm-$v/lib" >> "$GITHUB_ENV"
    fi
    ;;
  Darwin)
    echo "OPENSSL_DIR=$(brew --prefix openssl@3)" >> "$GITHUB_ENV"
    echo "LIBCLANG_PATH=$(xcode-select -p)/Toolchains/XcodeDefault.xctoolchain/usr/lib" >> "$GITHUB_ENV"
    ;;
  MINGW*|MSYS*|CYGWIN*)
    # OpenSSL built from source and linked statically (the runner has Strawberry Perl; NASM for its asm).
    choco install -y --no-progress nasm >/dev/null
    echo "C:\\Program Files\\NASM" >> "$GITHUB_PATH"
    # bindgen's libclang must match the clang headers it parses: with a VS environment (msvc-dev-cmd) those
    # are VS's bundled LLVM's, so use its libclang too (a different one fails on mmintrin.h's __m64).
    vs_llvm="${VCINSTALLDIR:-}Tools\\Llvm\\x64\\bin"
    if [ -n "${VCINSTALLDIR:-}" ] && [ -f "$(cygpath -u "$vs_llvm")/libclang.dll" ]; then
      echo "LIBCLANG_PATH=$vs_llvm" >> "$GITHUB_ENV"
    else
      echo "LIBCLANG_PATH=C:\\Program Files\\LLVM\\bin" >> "$GITHUB_ENV"
    fi
    # openssl-src's Configure needs a native perl: the bash steps would find Git's MSYS perl, which lacks
    # modules (Locale::Maketext::Simple) and makes OpenSSL configure for the wrong platform.
    echo "OPENSSL_SRC_PERL=C:\\Strawberry\\perl\\bin\\perl.exe" >> "$GITHUB_ENV"
    echo "PERL=C:\\Strawberry\\perl\\bin\\perl.exe" >> "$GITHUB_ENV"
    # The MSVC linker of the toolset msvc-dev-cmd selected, by full path: in bash steps, Git's coreutils
    # link.exe comes first in PATH, and rustc runs it once a VS environment is set.
    if [ -n "${VCToolsInstallDir:-}" ]; then
      echo "CARGO_TARGET_X86_64_PC_WINDOWS_MSVC_LINKER=${VCToolsInstallDir}bin\\Hostx64\\x64\\link.exe" >> "$GITHUB_ENV"
    fi
    # ext-php-rs needs nightly on Windows (PHP's handlers are __vectorcall: abi_vectorcall). Pinned.
    rustup toolchain install nightly-2026-11-01 --profile minimal >/dev/null
    echo "RUSTUP_TOOLCHAIN=nightly-2026-11-01" >> "$GITHUB_ENV"
    features="vendored-openssl"
    ;;
esac
echo "features=$features" >> "${GITHUB_OUTPUT:-/dev/null}"
