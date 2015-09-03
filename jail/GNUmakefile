all: execjail stderrtostdout

execjail: execjail.cc always
	$(CXX) -std=gnu++0x -W -Wall -g -O2 -o $@ $@.cc
	@if test `id -u` = 0; then \
	  echo "chown root:0 $@ && chmod u+s,g+s,go-w $@"; \
	  chown root:0 $@ && chmod u+s,g+s,go-w $@; \
	elif test "$$LOGNAME" = ec2-user; then \
	  echo "sudo chown root:0 $@ && sudo chmod u+s,g+s,go-w $@"; \
	  sudo chown root:0 $@ && sudo chmod u+s,g+s,go-w $@; \
	fi

stderrtostdout: stderrtostdout.c
	$(CC) -W -Wall -g -O2 -o $@ $^

clean:
	rm -f execjail stderrtostdout

always:
	@:

.PHONY: all clean always
