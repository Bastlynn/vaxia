# Drupal 7 test runner for VAXIA. PHP 7.4 matches the era of the code.
FROM php:7.4-apache
# Debian bullseye (php:7.4 base) is end-of-life; packages live on archive.debian.org.
RUN sed -i "s|deb.debian.org|archive.debian.org|g; /bullseye-updates/d" /etc/apt/sources.list \
  && apt-get update && apt-get install -y --no-install-recommends \
      libpng-dev libjpeg-dev libfreetype6-dev libzip-dev unzip git mariadb-client \
  && docker-php-ext-configure gd --with-jpeg --with-freetype \
  && docker-php-ext-install gd pdo_mysql zip opcache \
  && a2enmod rewrite \
  && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://getcomposer.org/download/2.7.9/composer.phar -o /usr/local/bin/composer && chmod +x /usr/local/bin/composer
RUN mkdir /opt/tools && cd /opt/tools \
  && composer require --no-interaction drush/drush:8.4.12 phpunit/phpunit:9.6.21 \
  && ln -s /opt/tools/vendor/bin/drush /usr/local/bin/drush \
  && ln -s /opt/tools/vendor/bin/phpunit /usr/local/bin/phpunit
RUN echo "memory_limit=1G\nmax_execution_time=0\nerror_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT\nsendmail_path=/bin/true" > /usr/local/etc/php/conf.d/vaxia.ini
# Line coverage (tests/run-coverage.sh). Xdebug is off unless the container
# runs with XDEBUG_MODE=coverage (run-coverage.sh restarts it that way); the
# prepend collects VAXIA lines only while tests/.build/coverage/ON exists.
# (pcov segfaults on this PHP 7.4 build on ARM64.)
RUN pecl install xdebug-3.1.6 && docker-php-ext-enable xdebug \
  && printf 'xdebug.mode=off\nauto_prepend_file=/var/www/tests/coverage/prepend.php\n' > /usr/local/etc/php/conf.d/zz-coverage.ini
ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html
