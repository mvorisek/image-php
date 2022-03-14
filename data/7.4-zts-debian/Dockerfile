FROM ci-target:base as basic

# install basic system tools
RUN (seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \
    && apt-get -y update \
    && apt-get -y upgrade \
    && apt-get -y install bash git make unzip gnupg ca-certificates apt-utils apt-transport-https netcat \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && git config --system url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system url."https://github.com/".insteadOf "ssh://git@github.com/"

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions \
    bcmath \
    exif \
    gd \
    gmp \
    igbinary \
    imagick \
    imap \
    intl \
    mysqli \
    oci8 \
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
    zip \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && rm -rf /usr/lib/oracle/* /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo '#!/bin/sh'; echo 'if [ ! -d /usr/lib/oracle/*/client64 ]; then'; echo '    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz'; echo 'fi'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh

# install Composer
RUN install-php-extensions @composer

FROM basic as basic__test
RUN php --version
COPY test.php ./
RUN (/usr/lib/oracle/setup.sh || true) && php test.php
RUN test -f /usr/local/lib/libphp7.so
RUN composer diagnose


FROM basic as node

# install Node JS with npm
RUN apt-get -y update \
    && apt-get -y install nodejs npm \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* && npm install --global npm@latest

FROM node as node__test
RUN npm version


FROM node as selenium

# install Selenium
RUN apt-get -y update \
    && apt-get -y install openjdk-11-jre-headless xvfb fonts-freefont-ttf \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN apt-get -y update \
    && apt-get -y install chromium chromium-driver libnss3-tools \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*

# install Firefox
RUN apt-get -y update \
    && apt-get -y install firefox-esr \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN chromium --version
RUN firefox --version
