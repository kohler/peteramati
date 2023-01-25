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

1. Run `lib/createdb.sh` to create the database. Use `lib/createdb.sh OPTIONS`
   to pass options to MariaDB, such as `--user` and `--password`. Many MariaDB
   installations require privilege to create tables, so you may need `sudo
   lib/createdb.sh OPTIONS`. Run `lib/createdb.sh --help` for more
   information. You will need to decide on a name for your database (no spaces
   allowed).

2. Edit the `conf/options.php` file with information about your class. The
   username and password information for the conference database is stored
   here.

3. Configure your web server so that all accesses to the Peteramati site are
   handled by its `index.php` script. The right way to do this depends on
   which server you’re running; we recommend Nginx with `php-fpm`.

    The example configurations below make `SITE/testclass` point to a
    Peteramati installation in `/home/kohler/peteramati`.

    **Nginx**: Configure Nginx to redirect all Peteramati accesses to
    `php-fpm`. This example code would go in a `server` block, and assumes
    that `php-fpm` is listening on port 9000:

    ```
    location /testclass/ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_split_path_info ^(/testclass)(/.*)$;
        fastcgi_param SCRIPT_FILENAME /home/kohler/peteramati/index.php;
        include fastcgi_params;
    }
    ```

    You may also set up separate `location` blocks so that Nginx directly
    serves static files under `/testclass/images/`, `/testclass/scripts/`, and
    `/testclass/stylesheets/`.

    **Apache with mod_proxy and `php-fpm`**: Add a `ProxyPass` for the
    Peteramati script.

    ```
    ProxyPass "/testclass" "fcgi://localhost:9000/home/kohler/peteramati/index.php"
    ```

    **Apache with mod_php (not recommended)**: Add a `ScriptAlias` for the
    Peteramati script and a `<Directory>` for the installation.

    ```
    ScriptAlias "/testclass" "/home/kohler/peteramati/index.php"
    <Directory "/home/kohler/peteramati">
      Options None
      AllowOverride none
      Require all denied
      <Files "index.php">
        Require all granted
      </Files>
    </Directory>
    ```

    **General notes**: Everything under Peteramati’s site path (here,
    `/testclass`) should be served by Peteramati. This normally happens
    automatically. However, if the site path is `/`, you may need to turn off
    your server’s default handlers for subdirectories such as `/doc`.

    The `images`, `scripts`, and `stylesheets` subdirectories contain static
    files that any user may access. It is safe to configure your server to
    serve those directories directly, without involving the Peteramati script.

4. Build the `pa-jail` program and other helper programs.

    ```sh
    cd jail && make
    ```

    The `pa-jail` program must be set-uid/gid root, so you may need to build
    it using `sudo make`.

5. Configure a GitHub OAuth app. Peteramati assumes you’re using GitHub to
   collect user information.

    * Create a new OAuth app on your organization’s Settings page. Set the
      callback URL to a prefix of the URL where your application will live;
      for instance, an application living at
      `https://cs61.seas.harvard.edu/grade/cs61-2019/` might have that
      callback URL, or callback URL `https://cs61.seas.harvard.edu/`.

    * Edit `conf/options.php` to set `$Opt["githubOAuthClientId"]` and
      `$Opt["githubOAuthClientSecret"]` to the app’s Client ID and Client
      Secret, respectively. These values are strings.

6. Create the first user on the site.

    ```sh
    $ lib/runsql.sh --create-user email@example.com firstName=First lastName=Last roles=2
    ```

    The `roles` parameter controls the user's privileges; students are 0,
    TF/TAs are 1, and the instructor privileges are 2.

7. Log in to the site. Now obtain a GitHub OAuth token, either by visiting
   `SITE/authorize`, or by configuring a personal access token and setting
   `$Opt["githubOAuthToken"]` explicitly in `conf/options.php`.

8. Configure the jail. The instructions below describe a simple setup, in
   which each student jail contains an actual copy of the files included in
   the jail. This can use substantial amounts of disk space for a large class;
   if this is a problem, you may want to configure a skeleton directory.

    * Create the directory where your jails will be, such as `/jails`. Now,
      create file `/etc/pa-jail.conf`, and in it, add a line saying
      `enablejail /jails/*`.

    * See [doc/runners.md](https://github.com/kohler/peteramati/blob/master/doc/runners.md) for
      more information about the jail layout.

9. Now configure the jail contents, which are defined by a manifest that
   contains a list of files to be copied into each jail when it is created. In
   the following, we will call this file `jfiles.txt`. You'll probably want to
   create it in the same place where you store your `psets.json` configuration
   file (see below); this location must be accessible to the web server.

10. Create a user under whose identity the jails run, such as `jail61user`.
    You can use your distribution's user add functionality (e.g., `adduser` or
    `useradd` commands) for this, but ensure to set no password for the user
    (it never needs to log in).

11. Now create your pset configuration file, `psets.json`. For content, see
    [the examples](https://github.com/kohler/peteramati/blob/main/doc/psetsjson.md)
    and the
    [schema](https://github.com/kohler/peteramati/blob/main/etc/pa-psets.schema.json).

    In this file, make sure to add the following entries (usually under `_defaults`):

    * `run_dirpattern`, which specifies where jails are. The format is something like
      `"/jails/repo${REPOID}.pset${PSET}"`, with `REPOID` and `PSET` set automatically
      by Peteramati.
    * `run_jailfiles`, which points to the `jfiles.txt` file created earlier.
    * `run_username`, which you should set to the name of the jail user created earlier
      (e.g., `jail61user`),

12. Populate the `jfiles.txt` manifest with the files needed to run student
    code. To do so, use the `pa-trace` command in the `jail/` subdirectory of
    your Peteramati installation.

    For example, the following command adds all files required to run `/bin/ls` inside the
    jail:

    ```sh
    ./pa-trace -o /home/kohler/class/cs61/jfiles.txt -x /jails /bin/ls
    ```

    The `-x` argument tells `pa-trace` to avoid including files inside the `/jails`
    directory in the list in `jfiles.txt`.

13. Your Peteramati installation is now ready for use!

License
-------

Peteramati is distributed under the GNU General Public License, version 2.0
(GPLv2). See LICENSE for a copy of this license.
