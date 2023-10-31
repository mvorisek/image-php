FROM ci-target:base as basic

# install basic system tools
RUN (seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \
    && apt-get -y update \
    && apt-get -y upgrade \
    && apt-get -y install bash git make unzip gnupg ca-certificates apt-utils apt-transport-https netcat-traditional gdb \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions
    && { echo '#!/bin/sh'; echo 'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then'; echo '    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")'; echo 'fi'; echo '/usr/bin/git "$@"'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_ICU_EN_ONLY=1 install-php-extensions bcmath
RUN IPE_ICU_EN_ONLY=1 install-php-extensions exif
RUN IPE_ICU_EN_ONLY=1 install-php-extensions gd
RUN IPE_ICU_EN_ONLY=1 install-php-extensions gmp
RUN IPE_ICU_EN_ONLY=1 install-php-extensions igbinary
RUN IPE_ICU_EN_ONLY=1 install-php-extensions Imagick/imagick@28f27044e4
RUN IPE_ICU_EN_ONLY=1 install-php-extensions imap
RUN IPE_ICU_EN_ONLY=1 install-php-extensions intl
RUN IPE_ICU_EN_ONLY=1 install-php-extensions mysqli
RUN IPE_ICU_EN_ONLY=1 install-php-extensions opcache
RUN IPE_ICU_EN_ONLY=1 install-php-extensions pcntl
RUN IPE_ICU_EN_ONLY=1 install-php-extensions pdo_mysql
RUN IPE_ICU_EN_ONLY=1 install-php-extensions oci8 pdo_oci \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && rm -r /usr/lib/oracle/* /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo '#!/bin/sh'; echo 'if [ ! -d /usr/lib/oracle/*/client64 ]; then'; echo '    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz'; echo 'fi'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh
RUN IPE_ICU_EN_ONLY=1 install-php-extensions pdo_pgsql
RUN IPE_ICU_EN_ONLY=1 install-php-extensions pdo_sqlsrv
RUN IPE_ICU_EN_ONLY=1 install-php-extensions redis
RUN IPE_ICU_EN_ONLY=1 install-php-extensions sockets
RUN IPE_ICU_EN_ONLY=1 install-php-extensions tidy
RUN IPE_ICU_EN_ONLY=1 install-php-extensions xdebug
RUN IPE_ICU_EN_ONLY=1 install-php-extensions xsl
RUN IPE_ICU_EN_ONLY=1 install-php-extensions zip

# install Composer
RUN install-php-extensions @composer

FROM basic as basic__test
RUN php --version
COPY test.php ./
RUN (/usr/lib/oracle/setup.sh || true) && php test.php
RUN php -n -r 'exit(ZEND_DEBUG_BUILD ? 0 : 1);'
RUN apt-get -y update \
    && apt-get -y install binutils \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*
RUN readelf -S /usr/local/bin/php | grep -q ' \.symtab ' \
    && readelf -S /usr/local/lib/libphp.so | grep -q ' \.symtab ' \
    && readelf -S "$(find /usr/local/lib/php/extensions -name bcmath.so)" | grep -q ' \.symtab ' \
    && readelf -S "$(find /usr/local/lib/php/extensions -name xdebug.so)" | grep -q ' \.symtab '
RUN composer diagnose
RUN mkdir t && (cd t && composer require phpunit/phpunit) && rm -r t/


FROM basic as node

# install Node JS with npm
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get -y update \
    && apt-get -y install nodejs \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* && npm install --global npm@latest

FROM node as node__test
RUN npm version
RUN mkdir t && (cd t && npm install mocha) && rm -r t/


FROM node as selenium

# install Selenium
RUN apt-get -y update \
    && apt-get -y install openjdk-17-jre-headless xvfb fonts-freefont-ttf \
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
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.33.0/geckodriver-v0.33.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN chromium --version
RUN firefox --version
