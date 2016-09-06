Peteramati
==========

Peteramati is a Web system for collecting, evaluating, and grading student
programming assignments. It collects student assignments using Git. Student
assignment code runs in Linux containers, segregated from the main machine.
Students can run test code themselves.

Peteramati is named after Peter Amati, one of my most important teachers. Mr.
Amati taught AP biology at Holliston High School, Holliston, Massachusetts. In
the classroom, he was exacting, passionate, warm, inspirational, and fun as
hell. -Eddie Kohler

Configuration
-------------

Peteramati is configured primarily through the `psets.json` file. See
[doc/psetsjson.md](doc/psetsjson.md) for more information.

Installation
------------

1. Run `lib/createdb.sh` to create the database. Use `lib/createdb.sh
OPTIONS` to pass options to MySQL, such as `--user` and `--password`.
Many MySQL installations require privilege to create tables, so you
may need `sudo lib/createdb.sh OPTIONS`. Run `lib/createdb.sh --help`
for more information. You will need to decide on a name for your
database (no spaces allowed).

2. Edit the `conf/options.php` file with information about your class.
The username and password information for the conference database is
stored here.

3. Configure your web server to access Peteramati. The right way to do
this depends on which server you’re running.

    **Apache**: Add a `<Directory>` to `httpd.conf` (or one of its
inclusions) for the Peteramati directory; an `Alias` redirecting your
preferred URL path to that directory; and a `FallbackResource` for
that URL path. This example makes `/testclass` point at a Peteramati
installation in /home/kohler/peteramati:

        # Apache 2.4 and later:
        <Directory "/home/kohler/peteramati">
            Options Indexes Includes FollowSymLinks
            AllowOverride all
            Require all granted
            FallbackResource /testclass/index.php
        </Directory>
        Alias /testclass /home/kohler/peteramati

    Note that the first argument to Alias should NOT end in a slash.
If you get an Error 500, see “Configuration notes”.

    Everything under Peteramati’s URL path (here, `/testclass`) should
be served by Peteramati. This normally happens automatically. However,
if the URL path is `/`, you may need to turn off your server’s default
handlers for subdirectories such as `/doc`.

    **Nginx**: Configure Nginx to access `php-fpm` for anything under
the Peteramati URL path. All accesses should be redirected to
`index.php`. This example, which would go in a `server` block, makes
`/testclass` point at a Peteramati installation in
/home/kohler/peteramati (assuming that the running `php-fpm` is
listening on port 9000):

        location /testclass/ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_split_path_info ^(/testclass)(/.*)$;
            fastcgi_param SCRIPT_FILENAME /home/kohler/peteramati/index.php;
            include fastcgi_params;
        }

    You may also set up separate `location` blocks so that Nginx
directly serves static files under `/testclass/images/`,
`/testclass/scripts/`, and `/testclass/stylesheets/`.

4. Build the `pa-jail` program and other helper programs.

        cd jail && make

    The `pa-jail` program must be set-uid/gid root, so you may need to build
    it using `sudo make`.

5. XXX Configure conf/gitssh_config and conf/sshid

6. XXX Configure the jail

License
-------

Peteramati is distributed under the GNU General Public License, version 2.0
(GPLv2). See LICENSE for a copy of this license.
