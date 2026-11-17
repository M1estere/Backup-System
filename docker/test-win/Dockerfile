# Тестовая «Windows-машина»: только sshd с SFTP. Пользователь winbackup / backuppass, папка /data
FROM debian:12-slim

RUN apt-get update && apt-get install -y --no-install-recommends openssh-server \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/sshd /data \
    && useradd -d /data -s /bin/bash winbackup \
    && echo 'winbackup:backuppass' | chpasswd \
    && chown winbackup:winbackup /data \
    && sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D", "-e"]
