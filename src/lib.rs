// Copyright 2026 Alexander Pankratov <s.pankratoff@gmail.com>
// Copyright 2026 Daniil Gentili <daniil@daniil.it>
// SPDX-License-Identifier: AGPL-3.0-only
#![cfg_attr(not(feature = "php-extension"), allow(dead_code))]
// PHP for Windows calls internal functions with __vectorcall (ZEND_FASTCALL): nightly-only in Rust.
#![cfg_attr(all(windows, feature = "php-extension"), feature(abi_vectorcall))]
mod crypto;
mod executor;
mod factorize;
#[cfg(any(target_arch = "aarch64", target_arch = "x86_64"))]
mod hardware;
#[cfg(feature = "php-extension")]
mod php;
#[cfg(feature = "php-extension")]
mod php_api;
#[cfg(feature = "php-extension")]
mod php_fast;
mod policy;
mod primitives;
#[cfg(test)]
mod primitives_tests;
#[cfg(test)]
mod tests;
