<?php

declare(strict_types=1);

namespace Mvorisek\Docker\ImagePhp;

$phpVersionsFromSource = [
    '7.4' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-7\.4\.[0-9]+',
        'forkPhpVersion' => '7.4', 'forkOsName' => ['alpine' => 'alpine3.16', 'debian' => 'bullseye'], 'forkRepoCommit' => '7388e44e40',
    ],
    '8.0' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.0\.[0-9]+',
        'forkPhpVersion' => '8.0', 'forkOsName' => ['alpine' => 'alpine3.16', 'debian' => 'bullseye'], 'forkRepoCommit' => '4c0c395658',
    ],
    '8.1' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.1\.[0-9]+',
        'forkPhpVersion' => '8.1', 'forkOsName' => ['alpine' => 'alpine3.19', 'debian' => 'bookworm'],
    ],
    '8.2' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.2\.[0-9]+',
        'forkPhpVersion' => '8.2', 'forkOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm'],
    ],
    '8.3' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.3\.[0-9]+',
        'forkPhpVersion' => '8.3', 'forkOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
    '8.4' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.4\.[0-9]+',
        'forkPhpVersion' => '8.4', 'forkOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
    '8.5' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/heads/master',
        'forkPhpVersion' => '8.5', 'forkOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
];
$osNames = ['alpine', 'debian'];
$targetNames = ['basic', 'node', 'selenium'];

$aliasesPhpVersions = [
    '8.3' => ['latest'],
];
$defaultOs = 'alpine';

$createFullName = function (string $imageName, string $targetName): string {
    return $imageName . ($targetName === 'basic' ? '' : '-' . $targetName);
};

$genImageTags = function (string $imageName) use ($aliasesPhpVersions, $defaultOs): array {
    $tags = [$imageName];
    foreach ($aliasesPhpVersions as $phpVersion => $aliases) {
        foreach ($aliases as $alias) {
            $v = preg_replace('~(?<!\d)' . preg_quote($phpVersion, '~') . '(?!\d)~', $alias, $imageName);
            if ($v !== $imageName) {
                $tags[] = $v;
            }
        }
    }

    $tagsBak = $tags;
    $tags = [];
    foreach ($tagsBak as $tag) {
        $v = preg_replace('~-' . preg_quote($defaultOs, '~') . '(?!\w)~', '', $tag);
        if ($v !== $tag) {
            $tags[] = $v;
        }
        $tags[] = $tag;
    }

    return $tags;
};

$genPackageInstallCommand = function ($osName, array $packages) {
    $packagesBeforeDiff = $packages;
    $packages = array_diff($packages, ['*upgrade*']);
    $upgrade = $packages !== $packagesBeforeDiff;
    unset($packagesBeforeDiff);

    $and = ' \\' . "\n" . '    && ';

    if ($osName === 'alpine') {
        return 'apk update'
            . ($upgrade ? $and . 'apk upgrade' : '')
            . $and . 'apk add ' . implode(' ', $packages)
            . $and . 'rm -rf /var/cache/apk/*';
    }

    return 'apt-get -y update'
        . ($upgrade ? $and . 'apt-get -y upgrade' : '')
        . $and . 'apt-get -y install ' . implode(' ', $packages)
        . $and . 'apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*';
};

$imageNames = [];
$phpVersionByImageName = [];
$isTsByImageName = [];
$osNameByImageName = [];
foreach ($osNames as $osName) {
    foreach (array_keys($phpVersionsFromSource) as $phpVersion) {
        foreach ([false, true] as $isDebug) {
            foreach ([false, true] as $isTs) {
                if ($isDebug && $isTs) {
                    continue;
                }

                $dockerFile = 'FROM ci-target:base AS basic

# install basic system tools
RUN ' . ($osName === 'debian' ? '(seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \\' . "\n" . '    && ' : '')
    . $genPackageInstallCommand($osName, [
        ...['*upgrade*', 'bash', 'git', 'make', 'unzip', 'gnupg', 'ca-certificates'],
        ...['alpine' => ['coreutils'], 'debian' => ['apt-utils', 'apt-transport-https', 'netcat-traditional']][$osName],
        ...($isDebug ? ['gdb'] : []),
    ]) . ' \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions' . /*
    see https://github.com/actions/checkout/issues/766, remove once fixed officially */ '
    && { echo \'#!/bin/sh\'; echo \'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then\'; echo \'    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")\'; echo \'fi\'; echo \'/usr/bin/git "$@"\'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
' . (in_array($phpVersion, ['8.5'], true) ? 'RUN git clone https://github.com/xdebug/xdebug.git -b master xdebug \
    && cd xdebug && git reset --hard 944c89eb66 && rm -r .git \
    && sed -E \'s~(<max>)[0-9]+.[0-9]+(.99</max>)~\199.99\2~\' -i package.xml && sed -E \'s~(if test "\$PHP_XDEBUG_FOUND_VERNUM" -ge ")[0-9]+(00"; then)~\19999\2~\' -i config.m4
' : '') . 'RUN IPE_ICU_EN_ONLY=1 install-php-extensions \
    ' . implode(' \\' . "\n" . '    ', [
        'bcmath',
        'exif',
        'gd',
        'gmp',
        'igbinary',
        'imagick',
        'intl',
        'mysqli',
        in_array($phpVersion, ['7.4', '8.0', '8.1'], true) ? 'oci8' : 'php/pecl-database-oci8@41dfb72698',
        ...(in_array($phpVersion, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'], true) ? ['opcache'] : []), // https://wiki.php.net/rfc/make_opcache_required
        'pcntl',
        'pdo_mysql',
        in_array($phpVersion, ['7.4', '8.0', '8.1', '8.2'], true) ? 'pdo_oci' : 'php/pecl-database-pdo_oci@e7a355e097',
        'pdo_pgsql',
        ...(in_array($phpVersion, ['8.5'], true) ? [] : ['pdo_sqlsrv']), // https://github.com/microsoft/msphpsql/issues/1523#issuecomment-2763338116
        'redis',
        'sockets',
        'tidy',
        in_array($phpVersion, ['8.5'], true) ? '$(realpath xdebug)' : 'xdebug',
        'xsl',
        'zip',
    ]) . ($osName === 'alpine' ? ' \
    # remove Ghostscript binary, reduce Alpine image size by 23 MB, remove once https://gitlab.alpinelinux.org/alpine/aports/-/issues/13415 is fixed
    && rm /usr/bin/gs' : '') . ' \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && rm -r /usr/lib/oracle/* /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo \'#!/bin/sh\'; echo \'if [ ! -d /usr/lib/oracle/*/client64 ]; then\'; echo \'    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz\'; echo \'fi\'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh

# install Composer
RUN install-php-extensions @composer

FROM basic AS basic__test
RUN php --version
COPY test.php ./
RUN (/usr/lib/oracle/setup.sh || true) && php test.php
RUN php -n -r \'exit(' . ($isDebug ? '' : '!') . 'ZEND_DEBUG_BUILD ? 0 : 1);\'
RUN ' . $genPackageInstallCommand($osName, ['binutils']) . '
RUN ' . implode(' \\' . "\n" . '    && ', array_map(function ($pathUnescaped) use ($isDebug) {
        return ($isDebug ? '' : '! ') . 'readelf -S ' . $pathUnescaped . ' | grep -q \' \.symtab \'';
    }, [
        '/usr/local/bin/php',
        '/usr/local/lib/libphp' . (in_array($phpVersion, ['7.4'], true) ? '7' : '') . '.so',
        '"$(find /usr/local/lib/php/extensions -name bcmath.so)"',
        '"$(find /usr/local/lib/php/extensions -name xdebug.so)"',
    ])) . '
RUN composer diagnose
RUN mkdir t && (cd t && ' . (in_array($phpVersion, ['8.5'], true) ? 'echo \'{}\' > composer.json && composer config platform.php 8.4 && ' : '') . 'composer require phpunit/phpunit) && rm -r t/


FROM basic AS node

# install Node JS with npm
RUN ' . ($osName === 'debian' ? 'curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && ' : '') . $genPackageInstallCommand($osName, ['nodejs', ...($osName === 'debian' ? [/* fix nodejs and npm apt config, drop once we migrate to Debian 12/Bookworm */] : ['npm'])])
    . ($osName === 'debian' ? ' && npm install --global npm@latest' : '') . '

FROM node AS node__test
RUN npm version
RUN mkdir t && (cd t && npm install mocha) && rm -r t/


FROM node AS selenium

# install Selenium
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['openjdk17-jre-headless', 'xvfb', 'ttf-freefont'], 'debian' => ['openjdk-17-jre-headless', 'xvfb', 'fonts-freefont-ttf']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['chromium', 'chromium-chromedriver', 'nss-tools'], 'debian' => ['chromium', 'chromium-driver', 'libnss3-tools']][$osName]) . '

# install Firefox
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['firefox'], 'debian' => ['firefox-esr']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.36.0/geckodriver-v0.36.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium AS selenium__test
RUN ' . ($osName === 'alpine' ? 'chromium-browser' : 'chromium') . ' --version
RUN firefox --version
';

                $dataDir = __DIR__ . '/data';
                $imageName = $phpVersion . ($isDebug ? '-debug' : '') . ($isTs ? '-zts' : '') . '-' . $osName;
                $imageNames[] = $imageName;
                $phpVersionByImageName[$imageName] = $phpVersion;
                $isTsByImageName[$imageName] = $isTs;
                $osNameByImageName[$imageName] = $osName;

                if (!is_dir($dataDir)) {
                    mkdir($dataDir);
                }
                if (!is_dir($dataDir . '/' . $imageName)) {
                    mkdir($dataDir . '/' . $imageName);
                }
                file_put_contents($dataDir . '/' . $imageName . '/Dockerfile', $dockerFile);
            }
        }
    }
}

// overcome Github Actions step code size limit, see https://github.com/github/feedback/discussions/12775
$genBatchedStepCode = function (\Closure $gen) use ($imageNames, $phpVersionByImageName): string {
    $codeParts = [];
    foreach (array_unique($phpVersionByImageName) as $phpVersion) {
        $imageNamesBatch = array_filter($imageNames, function ($imageName) use ($phpVersionByImageName, $phpVersion) {
            return $phpVersionByImageName[$imageName] === $phpVersion;
        });

        $codeRaw = $gen($imageNamesBatch);
        $code = preg_replace_callback('~( {6}- *name:[^\n]+)\'(?:\n {8}if: *(.+?))?(?=\n)~', function ($matches) use ($phpVersion, $imageNamesBatch) {
            return $matches[1] . ' - ' . $phpVersion . '.x\'' . "\n"
                . '        if: ' . (isset($matches[2]) ? '(' . $matches[2] . ') && ' : '') . '(' . (implode(' || ', array_map(function ($imageName) {
                    return 'matrix.imageName == \'' . $imageName . '\'';
                }, $imageNamesBatch)) ?: 'false') . ')';
        }, $codeRaw);

        $codeParts[] = $code;
    }

    return implode("\n\n", $codeParts);
};

$genRuntimeConditionalCode = function ($imageNames, \Closure $gen) use ($phpVersionByImageName, $isTsByImageName, $osNameByImageName): string {
    $imageNamesByCode = [];
    foreach ($imageNames as $imageName) {
        $code = $gen(
            $imageName,
            $phpVersionByImageName[$imageName],
            $isTsByImageName[$imageName],
            $osNameByImageName[$imageName]
        );

        if ($code !== null) {
            $imageNamesByCode[$code][] = $imageName;
        }
    }

    return implode("\n" . '          ; el', array_map(function ($code) use ($imageNamesByCode) {
        return 'if ' . implode(' || ', array_map(function ($imageName) {
            return '[ "${{ matrix.imageName }}" == "' . $imageName . '" ]';
        }, $imageNamesByCode[$code])) . '; then' . "\n" . '          ' . $code;
    }, array_keys($imageNamesByCode))) . (count($imageNamesByCode) === 0 ? 'true' : "\n" . '          ; fi');
};

$ciFile = 'name: CI

on:
  pull_request:
  push:
  schedule:
    - cron: \'20 2 1,15 * *\'

jobs:
  unit:
    name: Templating
    runs-on: ubuntu-latest
    container:
      image: ghcr.io/mvorisek/image-php
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: "Check if files are in-sync"
        run: |
          rm -r data/
          php make.php
          git add . -N && git diff --exit-code

  build:
    name: Build
    runs-on: ubuntu-latest
    env:
      REGISTRY_NAME: ghcr.io
      REGISTRY_IMAGE_NAME: ghcr.io/${{ github.repository }}
    strategy:
      fail-fast: false
      matrix:
        imageName:
' . implode("\n", array_map(function ($imageName) {
    return '          - "' . $imageName . '"';
}, $imageNames)) . '
    steps:
      - name: Checkout
        uses: actions/checkout@v4

' . $genBatchedStepCode(fn ($imageNames) => '      - name: \'Build base image - clone & patch\'
        run: >-
          ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'export PHPSRC_BRANCH="$(git ls-remote \'' . $phpVersionsFromSource[$phpVersion]['repo'] . '\' | grep -iE \'\s' . $phpVersionsFromSource[$phpVersion]['branchRegex'] . '$\' | sort -k2 -V | tee /dev/stderr | awk \'END{print $NF}\' | sed -E \'s~^refs/[^/]+/~~\')"';
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'git clone --depth 1 \'' . $phpVersionsFromSource[$phpVersion]['repo'] . '\' -b "$PHPSRC_BRANCH" phpsrc';
}) . '
          && cd phpsrc && export PHPSRC_COMMIT="$(git rev-parse HEAD)"
          && git checkout -B master
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return in_array($phpVersion, ['7.4', '8.0'], true)
        ? 'git apply -v ../fix-pdo_oci-bug60994--php74-80.patch && git -c user.name="a" -c user.email="a@a" commit -am "Fix pdo_oci ext NCLOB read - https://github.com/php/php-src/pull/8018"'
        : ($osName === 'alpine' && in_array($phpVersion, ['7.4', '8.0', '8.1', '8.2'], true) ? 'sed -E \'s~#if HAVE_OCILOBREAD2$~#if 1~\' -i ext/pdo_oci/oci_statement.c && git -c user.name="a" -c user.email="a@a" commit -am "Fix pdo_oci ext NCLOB read for Alpine - https://github.com/php/php-src/issues/8197"' : null);
}) . '
          && sudo apt-get -y update && sudo apt-get -y install bison re2c
          && scripts/dev/makedist > /dev/null && mv php-master-*.tar.xz php.tar.xz
          && git add . -N && git diff --diff-filter=d "$PHPSRC_COMMIT"
          && cd ..
          && git clone https://github.com/docker-library/php.git dlphp && cd dlphp
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return in_array($phpVersion, ['7.4', '8.0'], true)
        ? 'git checkout ' . $phpVersionsFromSource[$phpVersion]['forkRepoCommit']
        : null;
}) . '
          && rm -r [0-9].[0-9]*/ && sed -E \'s~( // )error\("missing GPG keys for " \+ env\.version\)~\1["x"]~\' -i Dockerfile-linux.template
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'echo \'{ "' . $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '": { "url": "x", "variants": [ "' . $phpVersionsFromSource[$phpVersion]['forkOsName'][$osName] . '/' . ($isTs ? 'zts' : 'cli') . '" ], "version": "' . preg_replace('~-rc\.$~', '.0RC', $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '.') . '99" } }\' > versions.json';
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return in_array($phpVersion, ['7.4'], true)
        ? 'git apply -v ../fix-dlphp-strip-gh1280--php74.patch'
        : null;
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return in_array($phpVersion, ['7.4', '8.0'], true)
        ? null
        : 'git apply -v ../revert-dlphp-gh1600-require-asc-url.patch';
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return (strpos($imageName, '-debug-') !== false ? 'DOCKER_PHP_ENABLE_DEBUG=1 ' : '') . './apply-templates.sh';
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'mv ' . $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '/' . $phpVersionsFromSource[$phpVersion]['forkOsName'][$osName] . '/' . ($isTs ? 'zts' : 'cli') . '/ img';
}) . '
          && cd img && mv ../../phpsrc/php.tar.xz .
          && sed -E \'s~^(ENV PHP_VERSION[ =]).*~\1CUSTOM--\'"$PHPSRC_BRANCH--$PHPSRC_COMMIT"\'~\' -i Dockerfile
          && sed -E \'s~^(ENV PHP_URL[ =]).*~COPY php.tar.xz /usr/src/~\' -i Dockerfile
          && sed -E \'s~^(ENV (GPG_KEYS|PHP_URL|PHP_ASC_URL|PHP_SHA256)[ =]).*~~\' -i Dockerfile
          && sed -E \'s~-n "\$(PHP_SHA256|PHP_ASC_URL)"~-n ""~\' -i Dockerfile
          && sed -E \'s~curl -fsSL -o php.tar.xz .*; ~~\' -i Dockerfile
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return $osName === 'debian' ? null : 'sed -E \'s~(--with-curl.*)( \\\\)~\1 --enable-embed\2~\' -i Dockerfile';
}) . '
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return in_array($phpVersion, ['7.4'], true) && strpos($imageName, '-debug-') !== false
        ? 'sed -E \'s~(--with-curl.*)( \\\\)~\1 --enable-debug\2~\' -i Dockerfile'
        : null;
}) . '
          && git add . -N && git diff --diff-filter=d') . '

' . $genBatchedStepCode(fn ($imageNames) => '      - name: \'Build base image - build\'
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          cd dlphp/img
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource, $createFullName) {
    $cacheFromImages = [
        'php:' . $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '-' . ($isTs ? 'zts' : 'cli') . '-' . $phpVersionsFromSource[$phpVersion]['forkOsName'][$osName],
        '$REGISTRY_IMAGE_NAME:' . $createFullName($imageName, 'base'),
    ];

    return implode("\n" . '          && ', array_map(function ($name) {
        return '((' . implode(' || ', array_fill(0, 2, 'docker pull "' . $name . '"')) . ') || true)';
    }, $cacheFromImages)) . '
          && (' . implode("\n" . '          || ', array_fill(0, 2, 'docker build ' . implode(' ', array_map(function ($name) {
        return '--cache-from "' . $name . '"';
    }, $cacheFromImages)) . ' -t "ci-target:base" ./')) . ')';
})) . '
' . implode("\n", array_map(function ($targetName) {
    $imageHashCmd = '$(docker inspect --format="{{.Id}}" "ci-target:' . $targetName . '")';

    return implode("\n", array_map(function ($targetName) {
        return '
      - name: \'Target "' . (substr($targetName, -6) === '__test' ? substr($targetName, 0, -6) . '" - test' : $targetName . '" - build') . '\'
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          sed -i \'s~^[ \t]*~~\' data/${{ matrix.imageName }}/Dockerfile
          && (' . implode("\n" . '          || ', array_fill(0, 2, 'docker build -f data/${{ matrix.imageName }}/Dockerfile --target "' . $targetName . '" -t "ci-target:' . $targetName . '" ./')) . ')';
    }, [$targetName, $targetName . '__test'])) . '

      - name: \'Target "' . $targetName . '" - display layer sizes\'
        run: >-
          docker history --no-trunc --format "table {{.CreatedSince}}\t{{.Size}}\t{{.CreatedBy}}" ' . $imageHashCmd . '
          && docker images --no-trunc --format "Total size: {{.Size}}\t{{.ID}}" | grep ' . $imageHashCmd . ' | cut -f1';
}, $targetNames)) . '

      - name: Login to registry
        uses: docker/login-action@v3
        with:
          registry: ${{ env.REGISTRY_NAME }}
          username: ${{ github.repository_owner }}
          password: ${{ secrets.GITHUB_TOKEN }}

' . $genBatchedStepCode(fn ($imageNames) => '      - name: \'Push tags to registry\'
        if: github.ref == \'refs/heads/master\'
        run: >-
          dtp() { docker tag "ci-target:$1" "$REGISTRY_IMAGE_NAME:$2" && docker push "$REGISTRY_IMAGE_NAME:$2"; }
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion) use ($targetNames, $genImageTags, $createFullName) {
    return implode("\n" . '          && ', array_merge(...array_map(function ($targetName) use ($genImageTags, $createFullName, $imageName) {
        return array_map(function ($imageTag) use ($targetName) {
            return 'dtp "' . $targetName . '" "' . $imageTag . '"';
        }, $genImageTags($createFullName($imageName, $targetName)));
    }, ['base', ...$targetNames])));
})) . '
';
file_put_contents(__DIR__ . '/.github/workflows/ci.yml', $ciFile);

$githubRepository = getenv('GITHUB_REPOSITORY') ?: 'mvorisek/image-php';
$registryImageName = getenv('REGISTRY_IMAGE_NAME') ?: 'ghcr.io/' . $githubRepository;

$readmeFile = '# Docker Images for PHP

[![CI](https://github.com/' . $githubRepository . '/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/' . $githubRepository . '/actions?query=branch:master)

This repository builds `' . $registryImageName . '` image and publishes the following tags:

' . implode("\n", array_map(function ($imageNameFull) use ($genImageTags) {
    return '- ' . implode(' ', array_map(function ($imageTag) {
        return '`' . $imageTag . '`';
    }, $genImageTags($imageNameFull)));
}, array_merge(
    ...array_map(function ($targetName) use ($imageNames, $createFullName) {
        return array_map(function ($imageName) use ($createFullName, $targetName) {
            return $createFullName($imageName, $targetName);
        }, $imageNames);
    }, $targetNames)
))) . '

## Running Locally

Run `php make.php` to regenerate Dockerfiles.
';
file_put_contents(__DIR__ . '/README.md', $readmeFile);
