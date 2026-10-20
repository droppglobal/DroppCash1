#!/bin/sh
# Prepares a fresh copy of the real database (+ the new migration) and runs the end-to-end test.
# Needs: MariaDB on /run/mysqld/mysqld.sock, the mock Flutterwave on 127.0.0.1:8093, and the SQL dump path as $1.
M="mariadb --socket=/run/mysqld/mysqld.sock -uroot"
$M -e "DROP DATABASE IF EXISTS t; CREATE DATABASE t CHARACTER SET utf8mb3;" || exit 1
$M t < "$1" || exit 1
$M t < "$(dirname "$0")/../sql/instant_withdraw.sql" || exit 1
php "$(dirname "$0")/e2e_instant.php"
