all: pa-jail stderrtostdout pa-jail-owner

pa-jail: pa-jail.cc
	$(CXX) -std=gnu++0x -W -Wall -g -O2 -o $@ $@.cc

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

stderrtostdout: stderrtostdout.c
	$(CC) -W -Wall -g -O2 -o $@ $^

clean:
	rm -f pa-jail stderrtostdout

always:
	@:

.PHONY: all clean always pa-jail-owner
