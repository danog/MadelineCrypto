# Built by cargo, not libtool: `all` builds modules/madeline_crypto.so, which install-modules copies.
all: modules/madeline_crypto.so

.PHONY: madeline-crypto-cargo
madeline-crypto-cargo:
	cd $(MADELINE_SRCDIR) && PHP="$(MADELINE_PHP)" PHP_CONFIG="$(MADELINE_PHP_CONFIG)" "$(MADELINE_CARGO)" build --release --locked

modules/madeline_crypto.so: madeline-crypto-cargo
	@mkdir -p modules
	@if test -f $(MADELINE_SRCDIR)/target/release/libmadeline_crypto.dylib; then \
		cp $(MADELINE_SRCDIR)/target/release/libmadeline_crypto.dylib $@; \
	else \
		cp $(MADELINE_SRCDIR)/target/release/libmadeline_crypto.so $@; \
	fi
