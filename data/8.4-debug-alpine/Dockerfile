FROM ci-target:base as basic

# install basic system tools
RUN apk update \
    && apk upgrade \
    && apk add bash git make unzip gnupg ca-certificates coreutils gdb \
    && rm -rf /var/cache/apk/* \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions
    && { echo '#!/bin/sh'; echo 'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then'; echo '    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")'; echo 'fi'; echo '/usr/bin/git "$@"'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN git clone --recurse-submodules https://github.com/phpredis/phpredis.git -b develop phpredis \
    && cd phpredis && git reset --hard d3b2d87b10 && rm -r .git
RUN git clone https://github.com/xdebug/xdebug.git -b master xdebug \
    && cd xdebug && git reset --hard 12adc6394a && rm -r .git \
    && sed -E 's~(<max>)[0-9]+.[0-9]+(.99</max>)~\199.99\2~' -i package.xml && sed -E 's~(if test "\$PHP_XDEBUG_FOUND_VERNUM" -ge ")[0-9]+(00"; then)~\19999\2~' -i config.m4
RUN IPE_ICU_EN_ONLY=1 install-php-extensions \
    bcmath \
    exif \
    gd \
    gmp \
    igbinary \
    Imagick/imagick@65e27f2bc0 \
    php/pecl-mail-imap@25b62dbf7b \
    intl \
    mysqli \
    php/pecl-database-oci8@74893c6e3d \
    opcache \
    pcntl \
    pdo_mysql \
    php/pecl-database-pdo_oci@be8a277c27 \
    pdo_pgsql \
    pdo_sqlsrv \
    $(realpath phpredis) \
    sockets \
    tidy \
    $(realpath xdebug) \
    xsl \
    zip \
    # remove Ghostscript binary, reduce Alpine image size by 23 MB, remove once https://gitlab.alpinelinux.org/alpine/aports/-/issues/13415 is fixed
    && rm /usr/bin/gs \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && rm -r /usr/lib/oracle/* /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo '#!/bin/sh'; echo 'if [ ! -d /usr/lib/oracle/*/client64 ]; then'; echo '    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz'; echo 'fi'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh

# install Composer
RUN install-php-extensions @composer

FROM basic as basic__test
RUN php --version
COPY test.php ./
RUN (/usr/lib/oracle/setup.sh || true) && php test.php
RUN php -n -r 'exit(ZEND_DEBUG_BUILD ? 0 : 1);'
RUN apk update \
    && apk add binutils \
    && rm -rf /var/cache/apk/*
RUN readelf -S /usr/local/bin/php | grep -q ' \.symtab ' \
    && readelf -S /usr/local/lib/libphp.so | grep -q ' \.symtab ' \
    && readelf -S "$(find /usr/local/lib/php/extensions -name bcmath.so)" | grep -q ' \.symtab ' \
    && readelf -S "$(find /usr/local/lib/php/extensions -name xdebug.so)" | grep -q ' \.symtab '
RUN composer diagnose
RUN mkdir t && (cd t && composer require phpunit/phpunit) && rm -r t/


FROM basic as node

# install Node JS with npm
RUN apk update \
    && apk add nodejs npm \
    && rm -rf /var/cache/apk/*

FROM node as node__test
RUN npm version
RUN mkdir t && (cd t && npm install mocha) && rm -r t/


FROM node as selenium

# install Selenium
RUN apk update \
    && apk add openjdk17-jre-headless xvfb ttf-freefont \
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
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.34.0/geckodriver-v0.34.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN chromium-browser --version
RUN firefox --version
