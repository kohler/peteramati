// pa-timeout.c -- Peteramati version of the `timeout` program

#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <unistd.h>
#include <sys/select.h>
#include <sys/time.h>
#include <sys/wait.h>
#include <errno.h>
#include <signal.h>

static int sigpipe[2];
static void sigchld_handler(int signo) {
    (void) signo;
    ssize_t w = write(sigpipe[1], "!", 1);
    (void) w;
}

static void usage() {
    fprintf(stderr, "Usage: pa-timeout TIMEOUT COMMAND [ARG...]\n");
    exit(125);
}

int main(int argc, char** argv) {
    if (argc < 3)
        usage();

    char* ends;
    double timeout = strtod(argv[1], &ends);
    if (*ends || argv[1] == ends)
        usage();

    if (pipe(sigpipe) == -1) {
        fprintf(stderr, "pipe: %s\n", strerror(errno));
        exit(125);
    }
    signal(SIGCHLD, sigchld_handler);

    pid_t p = fork();
    if (p == 0) {
        close(sigpipe[0]);
        close(sigpipe[1]);
        (void) execvp(argv[2], &argv[2]);
        fprintf(stderr, "%s: %s\n", argv[2], strerror(errno));
        exit(errno == ENOENT ? 127 : 126);
    } else if (p == (pid_t) -1) {
        fprintf(stderr, "fork: %s\n", strerror(errno));
        exit(125);
    }

    struct timeval now;
    gettimeofday(&now, NULL);
    struct timeval end, delta;
    delta.tv_sec = (long) timeout;
    delta.tv_usec = (long) ((timeout - delta.tv_sec) * 1000000);
    timeradd(&now, &delta, &end);
    fd_set rfds;
    FD_ZERO(&rfds);

    while (timercmp(&now, &end, <)) {
        FD_SET(sigpipe[0], &rfds);
        timersub(&end, &now, &delta);
        (void) select(sigpipe[0] + 1, &rfds, NULL, NULL, &delta);
        int status;
        pid_t wait = waitpid(p, &status, WNOHANG);
        if (wait == p) {
            if (WIFSIGNALED(status))
                exit(128 + WTERMSIG(status));
            else /* WIFEXITED(status) */
                exit(WEXITSTATUS(status));
        }
        gettimeofday(&now, NULL);
    }

    // timed out
    kill(p, SIGTERM);
    exit(124);
}
