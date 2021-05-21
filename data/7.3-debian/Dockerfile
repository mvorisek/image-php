FROM php:7.3-buster as base

# install basic system tools
RUN (seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \
    && apt-get -y update \
    && apt-get -y install bash git make unzip gnupg apt-utils apt-transport-https netcat \
    && apt-get -y autoremove && apt-get clean \
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


FROM base as node

# install Node JS with npm
RUN apt-get -y update \
    && apt-get -y install nodejs npm \
    && apt-get -y autoremove && apt-get clean && npm install --global npm@latest


FROM node as selenium

# install Selenium
RUN apt-get -y update \
    && apt-get -y install openjdk-11-jre-headless xvfb fonts-freefont-ttf \
    && apt-get -y autoremove && apt-get clean \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN apt-get -y update \
    && apt-get -y install chromium chromium-driver \
    && apt-get -y autoremove && apt-get clean

# install Firefox
RUN apt-get -y update \
    && apt-get -y install firefox-esr \
    && apt-get -y autoremove && apt-get clean \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver
