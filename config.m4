dnl PIE / phpize build: the extension is Rust (ext-php-rs). configure finds cargo and the PHP this build
dnl targets; make runs cargo and puts the library where `make install` takes it (modules/).
PHP_ARG_ENABLE([madeline_crypto], [whether to enable madeline_crypto],
  [AS_HELP_STRING([--enable-madeline_crypto], [Enable madeline_crypto (Rust; needs cargo, libclang and OpenSSL headers)])], [yes])
PHP_ARG_WITH([cargo], [cargo binary],
  [AS_HELP_STRING([--with-cargo=PATH], [Path to cargo (default: from PATH or ~/.cargo/bin)])], [no], [no])

if test "$PHP_MADELINE_CRYPTO" != "no"; then
  if test "$PHP_CARGO" != "no" && test "$PHP_CARGO" != "yes"; then
    MADELINE_CARGO="$PHP_CARGO"
  else
    AC_PATH_PROG([MADELINE_CARGO], [cargo], [no], [$PATH:$HOME/.cargo/bin])
  fi
  if test "$MADELINE_CARGO" = "no" || ! test -x "$MADELINE_CARGO"; then
    AC_MSG_ERROR([madeline_crypto is written in Rust: install it from https://rustup.rs (or pass --with-cargo=/path/to/cargo)])
  fi
  AC_MSG_CHECKING([for libclang (bindgen)])
  AC_MSG_RESULT([checked by cargo])
  dnl ext-php-rs reads the target PHP from these.
  case "$PHP_CONFIG" in
    /*) MADELINE_PHP_CONFIG="$PHP_CONFIG" ;;
    *) MADELINE_PHP_CONFIG="`command -v "$PHP_CONFIG"`" ;;
  esac
  MADELINE_PHP="`$MADELINE_PHP_CONFIG --php-binary 2>/dev/null`"
  MADELINE_SRCDIR="$abs_srcdir"
  PHP_SUBST([MADELINE_SRCDIR])
  PHP_SUBST([MADELINE_CARGO])
  PHP_SUBST([MADELINE_PHP])
  PHP_SUBST([MADELINE_PHP_CONFIG])
  PHP_ADD_MAKEFILE_FRAGMENT([$abs_srcdir/Makefile.frag])
fi
