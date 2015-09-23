#include <unistd.h>
#include <stdio.h>
#include <string.h>
#include <errno.h>
#include <stdlib.h>
#include <fcntl.h>

int main(int argc, char** argv) {
    int quiet = 0;
    if (argc >= 2 && strcmp(argv[1], "-q") == 0) {
        quiet = 1;
        --argc, ++argv;
    }
    if (argc != 2) {
        fprintf(stderr, "Usage: pa-writefifo [-q] FILE\n");
        exit(1);
    }

    int f = open(argv[1], O_WRONLY | O_TRUNC | O_NONBLOCK);
    if (f == -1) {
        if (!quiet)
            fprintf(stderr, "%s: %s\n", argv[1], strerror(errno));
        exit(1);
    }

    char buf[16384];
    size_t head = 0, tail = 0;
    int read_closed = 0;

    while (!read_closed || head != tail) {
        if (tail == sizeof(buf) && head != 0) {
            memmove(buf, &buf[head], tail - head);
            tail -= head;
            head = 0;
        }

        if (tail != sizeof(buf)) {
            ssize_t nr = read(STDIN_FILENO, &buf[tail], sizeof(buf) - tail);
            if (nr != 0 && nr != -1)
                tail += nr;
            else if (nr == 0)
                read_closed = 1;
            else if (errno != EINTR && errno != EAGAIN) {
                if (!quiet)
                    fprintf(stderr, "%s\n", strerror(errno));
                read_closed = 1;
            }
        }

        if (head != tail) {
            ssize_t nw = write(f, &buf[head], tail - head);
            if (nw != 0 && nw != -1)
                head += nw;
            else if (errno != EINTR && errno != EAGAIN) {
                if (!quiet)
                    fprintf(stderr, "%s: %s\n", argv[1], strerror(errno));
                exit(1);
            }
        }
    }

    exit(0);
}
