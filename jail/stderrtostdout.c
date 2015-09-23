#include <unistd.h>
#include <stdio.h>
#include <string.h>
#include <errno.h>
#include <stdlib.h>

int main(int argc, char** argv) {
    if (argc < 2) {
        fprintf(stderr, "Usage: stderrtostdout COMMAND [ARG...]\n");
        exit(1);
    }
    close(STDERR_FILENO);
    dup2(STDOUT_FILENO, STDERR_FILENO);
    execvp(argv[1], &argv[1]);
    fprintf(stderr, "%s\n", strerror(errno));
    exit(1);
}
