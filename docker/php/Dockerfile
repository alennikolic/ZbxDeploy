# docker/php/Dockerfile
FROM php:8.3-fpm-bookworm

ARG ANSIBLE_CORE_VERSION=2.17.*

# System packages: libsodium for encryption, SSH client + sshpass for Ansible
# transport, python3 for ansible-core itself.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        libsodium-dev \
        openssh-client \
        python3 \
        python3-pip \
        sshpass \
        unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" pdo_mysql sodium pcntl

# ansible-core only: we ship our own roles, no need for the full community bundle.
RUN pip3 install --no-cache-dir --break-system-packages "ansible-core==${ANSIBLE_CORE_VERSION}"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies first so they are cached independently of app code.
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader \
    && chown -R www-data:www-data /app/vendor

COPY . /app

# /data holds SSH material, gathered facts and scratch inventories.
# Ownership is inherited by the named volume on first start.
RUN mkdir -p /data/ssh /data/facts /data/tmp \
    && touch /data/ssh/known_hosts \
    && chown -R www-data:www-data /data \
    && chmod 700 /data/ssh \
    && chmod 600 /data/ssh/known_hosts

ENV ANSIBLE_CONFIG=/app/ansible/ansible.cfg \
    PYTHONUNBUFFERED=1

EXPOSE 9000
CMD ["php-fpm"]
