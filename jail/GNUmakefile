PREFIX ?= /usr/local
BINDIR = $(DESTDIR)$(PREFIX)/bin
# SANFLAGS = -fsanitize=address -fsanitize=undefined
SANFLAGS =

all: pa-timeout pa-jail pa-jail-owner

pa-jail: pa-jutil.o pa-jailconf.o pa-jail.o
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -o $@ $^

test-pa-jailconf: pa-jutil.o pa-jailconf.o test-pa-jailconf.o
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -o $@ $^

pa-jail.o pa-jailconf.o pa-jutil.o test-pa-jailconf.o: %.o: %.cc
	$(CXX) -std=gnu++20 -W -Wall -g -O2 $(SANFLAGS) -c -o $@ $<

pa-jail.o pa-jailconf.o test-pa-jailconf.o: pa-jailconf.hh
pa-jail.o pa-jailconf.o pa-jutil.o test-pa-jailconf.o: pa-jutil.hh

pa-jail-owner: pa-jail
	@ok=`find $< -user root -a -group 0 -a -perm -u+s,g+rxs,g-w,o+rx,o-w -print`; \
	if test -n "$$ok"; then :; \
	elif test `id -u` = 0; then \
	  echo "chown root:0 $< && chmod u+s,g+s,go-w $<"; \
	  chown root:0 $< && chmod u+s,g+s,go-w $<; \
	else \
	  echo "sudo -n chown root:0 $< && sudo -n chmod u+s,g+s,go-w $<"; \
	  sudo -n chown root:0 $< && sudo -n chmod u+s,g+s,go-w $<; \
	fi

pa-timeout: pa-timeout.c
	$(CC) -std=gnu11 -W -Wall -g -O2 -o $@ $^

pa-writefifo: pa-writefifo.c
	$(CC) -std=gnu11 -W -Wall -g -O2 -o $@ $^

clean:
	rm -f pa-jail pa-timeout pa-writefifo test-pa-jailconf *.o

install: pa-jail pa-timeout
	install -d $(BINDIR)
	install -mu+rwxs,g+rxs,o+rx pa-jail $(BINDIR)
	install pa-timeout pa-trace $(BINDIR)

always:
	@:

.PHONY: all clean install always pa-jail-owner check

check: test-pa-jailconf
	./test-pa-jailconf
