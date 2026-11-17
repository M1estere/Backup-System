# Тестовый «сервер-источник»: sshd + MariaDB + фейковый сайт. Пароль root: rootpass
FROM debian:12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        openssh-server mariadb-server mariadb-client tar gzip ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/sshd \
    && echo 'root:rootpass' | chpasswd \
    && sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin yes/; s/^#\?PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config

# Фейковый сайт
RUN mkdir -p /var/www/site/upload /var/www/site/cache \
    && echo '<?php echo "hello";' > /var/www/site/index.php \
    && head -c 5000000 /dev/urandom > /var/www/site/upload/big.bin \
    && echo 'temp' > /var/www/site/cache/tmp.txt

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 22
CMD ["/entrypoint.sh"]
