ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache

# install-php-extensions handles pre-built extension detection automatically
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# System tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    msmtp \
    unzip \
    git \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    bcmath \
    mbstring \
    xml \
    curl \
    zip \
    intl \
    opcache \
    pcntl \
    exif \
    redis

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache: enable mod_rewrite, set DocumentRoot to /var/www/html/public
RUN a2enmod rewrite remoteip

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
    /etc/apache2/sites-available/000-default.conf && \
    printf '<Directory /var/www/html/public>\n\tOptions -Indexes +FollowSymLinks\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
    >> /etc/apache2/sites-available/000-default.conf

# Trust Traefik proxy headers
RUN echo 'RemoteIPHeader X-Forwarded-For' >> /etc/apache2/conf-available/docker-php.conf && \
    echo 'RemoteIPInternalProxy 172.16.0.0/12' >> /etc/apache2/conf-available/docker-php.conf && \
    echo 'SetEnvIf X-Forwarded-Proto "https" HTTPS=on' >> /etc/apache2/conf-available/docker-php.conf

WORKDIR /var/www/html
