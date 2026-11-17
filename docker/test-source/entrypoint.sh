#!/bin/sh
set -e

mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
mysqld_safe --skip-networking=0 >/var/log/mysqld.log 2>&1 &

# ждём MariaDB и создаём тестовую БД
for i in $(seq 1 30); do
  mysqladmin ping --silent 2>/dev/null && break
  sleep 1
done

mysql -e "CREATE DATABASE IF NOT EXISTS testsite;
CREATE USER IF NOT EXISTS 'site'@'localhost' IDENTIFIED BY 'sitepass';
GRANT ALL ON testsite.* TO 'site'@'localhost';
USE testsite;
CREATE TABLE IF NOT EXISTS posts (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255));
INSERT INTO posts (title) SELECT 'hello' WHERE NOT EXISTS (SELECT 1 FROM posts);
FLUSH PRIVILEGES;"

exec /usr/sbin/sshd -D -e
