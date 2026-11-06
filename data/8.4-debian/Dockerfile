FROM alpine:latest AS patched-dlphp

# install basic system tools
RUN apk update \
    && apk upgrade \
    && apk add bash git make unzip gnupg ca-certificates coreutils \
    && rm -rf /var/cache/apk/* \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions
    && { echo '#!/bin/sh'; echo 'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then'; echo '    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")'; echo 'fi'; echo '/usr/bin/git "$@"'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

RUN git clone --depth 1 'https://github.com/php/php-src.git' -b "$(git ls-remote 'https://github.com/php/php-src.git' | grep -iE '\srefs/tags/PHP-8\.4\.[0-9]+$' | sort -k2 -V | tee /dev/stderr | awk 'END{print $NF}' | sed -E 's~^refs/[^/]+/~~')" phpsrc
RUN git clone https://github.com/docker-library/php.git dlphp
RUN apk update \
    && apk add bison re2c tar xz jq gawk autoconf \
    && rm -rf /var/cache/apk/*
COPY patches patches
WORKDIR phpsrc
RUN git checkout -B master
RUN scripts/dev/makedist > /dev/null && mv php-master-*.tar.xz php.tar.xz
RUN git add . -N && git diff --diff-filter=d "$(git rev-parse HEAD)"
WORKDIR ../dlphp
RUN rm -r [0-9].[0-9]*/ && sed -E 's~( // )error\("missing GPG keys for " \+ env\.version\)~\1["x"]~' -i Dockerfile-linux.template
RUN echo '{ "8.4": { "url": "x", "variants": [ "bookworm/cli" ], "version": "8.4.99" } }' > versions.json

RUN git apply -v ../patches/revert-dlphp-gh1600-require-asc-url.patch
RUN ./apply-templates.sh
RUN mv 8.4/bookworm/cli/ img
WORKDIR img
RUN mv ../../phpsrc/php.tar.xz .
RUN sed -E 's~^(ENV PHP_VERSION[ =]).*~\1CUSTOM--'"$(git ls-remote 'https://github.com/php/php-src.git' | grep -iE '\srefs/tags/PHP-8\.4\.[0-9]+$' | sort -k2 -V | tee /dev/stderr | awk 'END{print $NF}' | sed -E 's~^refs/[^/]+/~~')--$(git rev-parse HEAD)"'~' -i Dockerfile
RUN sed -E 's~^(ENV PHP_URL[ =]).*~COPY php.tar.xz /usr/src/~' -i Dockerfile
RUN sed -E 's~^(ENV (GPG_KEYS|PHP_URL|PHP_ASC_URL|PHP_SHA256)[ =]).*~~' -i Dockerfile
RUN sed -E 's~-n "\$(PHP_SHA256|PHP_ASC_URL)"~-n ""~' -i Dockerfile
RUN sed -E 's~curl -fsSL -o php.tar.xz .*; ~~' -i Dockerfile
RUN git add . -N && git diff --diff-filter=d


FROM ci-target:base AS basic

# install basic system tools
RUN (seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \
    && apt-get update \
    && apt-get -y upgrade \
    && apt-get -y install bash git make unzip gnupg ca-certificates apt-utils apt-transport-https netcat-traditional \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions
    && { echo '#!/bin/sh'; echo 'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then'; echo '    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")'; echo 'fi'; echo '/usr/bin/git "$@"'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_ICU_EN_ONLY=1 install-php-extensions \
    bcmath \
    exif \
    gd \
    gmp \
    igbinary \
    imagick \
    intl \
    mysqli \
    php/pecl-database-oci8@e4c20008b0 \
    opcache \
    pcntl \
    pdo_mysql \
    php/pecl-database-pdo_oci@6575b4c5fe \
    pdo_pgsql \
    pdo_sqlsrv \
    redis \
    sockets \
    tidy \
    xdebug \
    xsl \
    zip \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/xx-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/xx-php-ext-oci8.ini && rm -r /usr/lib/oracle/* /usr/local/etc/php/conf.d/xx-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/xx-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo '#!/bin/sh'; echo 'if [ ! -d /usr/lib/oracle/*/client64 ]; then'; echo '    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz'; echo 'fi'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh

# install Composer
RUN install-php-extensions @composer

FROM basic AS basic__test
RUN php --version
COPY test.php ./
RUN (/usr/lib/oracle/setup.sh || true) && php test.php
RUN php -n -r 'exit(!ZEND_DEBUG_BUILD ? 0 : 1);'
RUN apt-get update \
    && apt-get -y install binutils \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*
RUN ! readelf -S /usr/local/bin/php | grep -q ' \.symtab ' \
    && ! readelf -S /usr/local/lib/libphp.so | grep -q ' \.symtab ' \
    && ! readelf -S "$(find /usr/local/lib/php/extensions -name bcmath.so)" | grep -q ' \.symtab ' \
    && ! readelf -S "$(find /usr/local/lib/php/extensions -name xdebug.so)" | grep -q ' \.symtab '
RUN mkdir t && (cd t && composer require symfony/console) && rm -r t/


FROM basic AS node

# install Node JS with npm
RUN curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get update \
    && apt-get -y install nodejs \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* && npm install --global npm@latest

FROM node AS node__test
RUN npm version
RUN mkdir t && (cd t && npm install mocha) && rm -r t/


FROM node AS selenium

# install Selenium
RUN apt-get update \
    && apt-get -y install openjdk-17-jre-headless xvfb fonts-freefont-ttf \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN apt-get update \
    && apt-get -y install chromium chromium-driver libnss3-tools \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*

# install Firefox
RUN apt-get update \
    && apt-get -y install firefox-esr \
    && apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.36.0/geckodriver-v0.36.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium AS selenium__test
RUN chromium --version
RUN firefox --version
