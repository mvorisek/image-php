FROM php:8.0-alpine as base

# install basic system tools
RUN apk update \
    && apk add bash git make unzip gnupg coreutils \
    && rm -rf /var/cache/apk/* \
    && git config --system url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system url."https://github.com/".insteadOf "ssh://git@github.com/"

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath \
    exif \
    gd \
    gmp \
    igbinary \
    imagick \
    imap \
    intl \
    mysqli \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_oci \
    pdo_pgsql \
    pdo_sqlsrv \
    redis \
    sockets \
    tidy \
    xdebug \
    xsl \
    zip

# install Composer
RUN install-php-extensions @composer

# run basic tests
COPY test.php ./
RUN php test.php && rm test.php
RUN composer diagnose


FROM base as npm

# install Node JS with npm
RUN apk update \
    && apk add nodejs npm \
    && rm -rf /var/cache/apk/*


FROM npm as selenium

# install Selenium
RUN apk update \
    && apk add openjdk11-jre-headless xvfb ttf-freefont \
    && rm -rf /var/cache/apk/* \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN apk update \
    && apk add chromium chromium-chromedriver \
    && rm -rf /var/cache/apk/*

# install Firefox
RUN apk update \
    && apk add firefox \
    && rm -rf /var/cache/apk/* \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver
