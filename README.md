# MadelineCrypto

Native (Rust) cryptography extension for [MadelineProto](https://github.com/danog/MadelineProto) and
[php-rtc](https://github.com/danog/php-rtc): MTProto AES-256-IGE and transport decoding on a worker
thread, per-packet AES-GCM/CTR/CBC, hashes and HMAC several times faster than the PHP builtins on small
inputs, and the factorization of the auth key exchange. Optional: without it, MadelineProto and php-rtc
use their PHP implementations.

## Install

With [PIE](https://github.com/php/pie) (PHP 8.2+, NTS):

```sh
pie install danog/madelinecrypto
```

Releases include prebuilt binaries for Linux (glibc 2.35+, x86_64 and arm64), macOS (arm64 and x86_64) and
Windows (x86_64), for PHP 8.2 to 8.5. Elsewhere PIE builds from source, which needs
[Rust](https://rustup.rs), libclang and the OpenSSL headers. The `danog/madelineproto` Docker image
includes the extension.

## API

Namespace `danog\MadelineProto\Native`, see [`stubs/crypto.php`](stubs/crypto.php). `Crypto::version()`
is the API version (`0.6.0`); callers check it and keep a PHP fallback.

- `Crypto`: `init()` starts the worker thread (bridged to Revolt by
  [php-tokio](https://github.com/danog/php-tokio)), `ige()` and `decode()` run on it, `decodeSync()` on the
  PHP thread; `factorize()` returns the smallest prime factor of a 64-bit integer (MTProto's `pq`).
- `AesGcm`, `AesCtr`, `AesCtrKey`, `AesCbc`, `AesIge`, `Hmac`, `Hash`: synchronous, no `init()`
  needed. Key objects schedule their key once, zeroize it when freed, and can't be cloned or serialized.
  Outputs match the PHP builtins byte for byte; invalid sizes or algorithms throw `\ValueError`, and a GCM
  tag mismatch returns `false` (constant-time check).

## Build from source

```sh
phpize && ./configure && make && sudo make install   # what PIE runs; configure takes --with-cargo=PATH
```

or `cargo build --release --locked` and copy `target/release/libmadeline_crypto.{so,dylib}` (or
`madeline_crypto.dll`) to PHP's extension directory as `madeline_crypto.so` (`php_madeline_crypto.dll`).
The `vendored-openssl` feature links OpenSSL statically, as the prebuilt binaries do. On Windows, build with
nightly Rust: PHP calls extension functions with `__vectorcall`, which needs Rust's unstable
`abi_vectorcall`.

## Tests

```sh
cargo test --release --locked --no-default-features --features const-widths
cargo build --release --locked && composer install
php -d extension=$(tests/library-path.sh) vendor/bin/phpunit
```

CI runs them on Linux, macOS and Windows with PHP 8.2 to 8.5, and installs the extension with PIE.

## Design

Internals (the executor, batching, backend choices and their measurements): [DESIGN.md](DESIGN.md).

## License

AGPL-3.0-only.
