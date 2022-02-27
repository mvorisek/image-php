FROM php:8.0-alpine as base

# install basic system tools
RUN apk update \
    && apk upgrade \
    && apk add bash git make unzip gnupg ca-certificates coreutils \
    && rm -rf /var/cache/apk/* \
    && git config --system url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system url."https://github.com/".insteadOf "ssh://git@github.com/"

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions bcmath
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions exif
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions gd
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions gmp
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions igbinary
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions imagick
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions imap
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions intl
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions mysqli
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions oci8
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions opcache
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions pcntl
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions pdo_mysql
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions pdo_oci
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions pdo_pgsql
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions pdo_sqlsrv
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions redis
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions sockets
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions tidy
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions xdebug
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions xsl
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions zip \
    && rm /usr/bin/gs

# install Composer
RUN install-php-extensions @composer

FROM base as base__test
COPY test.php ./
RUN php test.php && rm test.php
RUN composer diagnose


FROM base as node

# install Node JS with npm
RUN apk update \
    && apk add nodejs npm \
    && rm -rf /var/cache/apk/*

FROM node as node__test
RUN npm version


FROM node as selenium

# install Selenium
RUN apk update \
    && apk add openjdk11-jre-headless xvfb ttf-freefont \
    && rm -rf /var/cache/apk/* \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN apk update \
    && apk add chromium chromium-chromedriver nss-tools \
    && rm -rf /var/cache/apk/*

# install Firefox
RUN apk update \
    && apk add firefox \
    && rm -rf /var/cache/apk/* \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN chromium-browser --version
RUN firefox --version
