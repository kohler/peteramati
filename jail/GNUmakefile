PREFIX ?= /usr/local
BINDIR = $(DESTDIR)$(PREFIX)/bin
# SANFLAGS = -fsanitize=address -fsanitize=undefined
SANFLAGS =
DEFS =
ifeq ($(PA_HAVE_CGROUP),0)
DEFS += -DPA_HAVE_CGROUP=0
else ifeq ($(PA_HAVE_CGROUP),1)
DEFS += -DPA_HAVE_CGROUP=1
endif

# Support out-of-tree builds via `make -f SRCDIR/GNUmakefile`. `$(srcdir)` is
# this makefile's directory (`.` for an in-tree build); objects and binaries
# are written to the current (build) directory. `vpath` finds sources in
# `$(srcdir)`.
srcdir := $(patsubst %/,%,$(dir $(lastword $(MAKEFILE_LIST))))
vpath %.cc $(srcdir)
vpath %.c $(srcdir)
vpath %.hh $(srcdir)

all: pa-timeout pa-jail pa-jail-owner

pa-jail: pa-jutil.o pa-jailconf.o pa-jail.o
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -o $@ $^

test-pa-jailconf: pa-jutil.o pa-jailconf.o test-pa-jailconf.o
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -o $@ $^

# end-to-end driver; runs the pa-jail binary (locally or, with --docker, in a
# container). Standalone, so it builds on both Linux and macOS.
test-pa-jail: test-pa-jail.o
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -o $@ $^

pa-jail.o pa-jailconf.o pa-jutil.o test-pa-jailconf.o test-pa-jail.o: %.o: %.cc
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) $(DEFS) -I$(srcdir) -c -o $@ $<

pa-jail.o pa-jailconf.o test-pa-jailconf.o: pa-jailconf.hh
pa-jail.o pa-jailconf.o pa-jutil.o test-pa-jailconf.o: pa-jutil.hh

pa-jail-owner: pa-jail
	-@ok=`find $< -user root -a -group 0 -a -perm -u+s,g+rxs,g-w,o+rx,o-w -print`; \
	if test -n "$$ok" -o "`uname`" '!=' Linux; then :; \
	elif test `id -u` = 0; then \
	  echo "chown root:0 $< && chmod u+s,g+s,go-w $<"; \
	  chown root:0 $< && chmod u+s,g+s,go-w $<; \
	else \
	  echo "sudo -n chown root:0 $< && sudo -n chmod u+s,g+s,go-w $<"; \
	  sudo -n chown root:0 $< && sudo -n chmod u+s,g+s,go-w $<; \
	fi

pa-timeout: pa-timeout.c
	$(CC) -std=gnu11 -W -Wall -g -O2 -I$(srcdir) -o $@ $^

pa-writefifo: pa-writefifo.c
	$(CC) -std=gnu11 -W -Wall -g -O2 -I$(srcdir) -o $@ $^

clean:
	rm -rf pa-jail pa-timeout pa-writefifo test-pa-jailconf test-pa-jail *.o *.dSYM

install: pa-jail pa-timeout
	install -d $(BINDIR)
	install -mu+rwxs,g+rxs,o+rx pa-jail $(BINDIR)
	install pa-timeout $(srcdir)/pa-trace $(BINDIR)

always:
	@:

.PHONY: all clean install always pa-jail-owner check check-docker check-jail check-jail-docker

check: test-pa-jailconf
	./test-pa-jailconf

# Run the end-to-end jail tests in an ephemeral container (needs Docker; works
# from macOS). `make check-jail` runs them locally instead (needs root + Linux).
check-jail-docker: test-pa-jail
	./test-pa-jail --docker

check-jail: test-pa-jail
	./test-pa-jail

# Build and run the test suite in an ephemeral Linux container. Useful when the
# host's endpoint-security software quarantines freshly-built binaries, and as a
# check on pa-jail's real (Linux) target platform. See build-docker.sh.
check-docker: test-pa-jail
	./build-docker.sh
	./test-pa-jail --docker
