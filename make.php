<?php

declare(strict_types=1);

namespace Mvorisek\Docker\ImagePhp;

$phpVersionsFromSource = [
    '8.2' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.2\.[0-9]+',
        'forkPhpVersion' => '8.2-rc', 'forkOsName' => ['alpine' => 'alpine3.17', 'debian' => 'bullseye'],
    ],
    '8.3' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/heads/master',
        'forkPhpVersion' => '8.2-rc', 'forkOsName' => ['alpine' => 'alpine3.17', 'debian' => 'bullseye']
    ],
];
$osNames = ['alpine', 'debian'];
$targetNames = ['basic'];

$aliasesPhpVersions = [
    '8.1' => ['latest'],
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

                $imageName = $phpVersion . ($isDebug ? '-debug' : '') . ($isTs ? '-zts' : '') . '-' . $osName;
                $dockerFile = 'FROM ghcr.io/mvorisek/image-php:' . $genImageTags($createFullName($imageName, 'base'))[0] . ' as basic

# install basic system tools
RUN ' . ($osName === 'debian' ? '(seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \\' . "\n" . '    && ' : '')
    . $genPackageInstallCommand($osName, [
        ...['*upgrade*', 'bash', 'git', 'make', 'unzip', 'gnupg', 'ca-certificates'],
        ...['alpine' => ['coreutils'], 'debian' => ['apt-utils', 'apt-transport-https', 'netcat']][$osName],
        ...($isDebug ? ['gdb'] : []),
    ]) . ' \
    && git config --system --add url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/" \
    # fix git repository directory is owned by someone else for Github Actions' . /*
    see https://github.com/actions/checkout/issues/766, remove once fixed officially */ '
    && { echo \'#!/bin/sh\'; echo \'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then\'; echo \'    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")\'; echo \'fi\'; echo \'/usr/bin/git "$@"\'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN sed \'s~rm -rf /tmp/pear~~\' -i /usr/local/bin/install-php-extensions
' . (in_array($phpVersion, ['8.3'], true) ? 'RUN git clone --depth 1 https://github.com/xdebug/xdebug.git -b master xdebug \
    && cd xdebug && git reset --hard 28f528d0ef \
    && sed \'s~<max>8.2.99</max>~<max>8.3.0</max>~\' -i package.xml
' : '') . 'RUN IPE_ICU_EN_ONLY=1 install-php-extensions \
    ' . implode(' \\' . "\n" . '    ', [
        'bcmath',
        'exif',
        'gd',
        'gmp',
        'igbinary',
        'imagick',
        'imap',
        'intl',
        'mysqli',
        'oci8',
        'opcache',
        'pcntl',
        'pdo_mysql',
        'pdo_oci',
        'pdo_pgsql',
        'pdo_sqlsrv',
        'redis',
        'sockets',
        'tidy',
        in_array($phpVersion, ['8.3'], true) ? '$(realpath xdebug)' : 'xdebug',
        'xsl',
        'zip',
    ]) . ($osName === 'alpine' ? ' || (cat /tmp/pear/temp/imagick/Imagick.stub.php && cat /tmp/pear/temp/imagick/ImagickKernel_arginfo.h && cat force-failure-unexistent) && (cat /tmp/pear/temp/imagick/Imagick.stub.php && cat /tmp/pear/temp/imagick/ImagickKernel_arginfo.h) \
    # remove Ghostscript binary, reduce Alpine image size by 23 MB, remove once https://gitlab.alpinelinux.org/alpine/aports/-/issues/13415 is fixed
    && rm /usr/bin/gs' : '') . ' \
    # pack Oracle Instant Client libs, reduce image size by 85 MB
    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && rm -rf /usr/lib/oracle/* /usr/local/etc/php/conf.d/docker-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/docker-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz \
    && { echo \'#!/bin/sh\'; echo \'if [ ! -d /usr/lib/oracle/*/client64 ]; then\'; echo \'    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz\'; echo \'fi\'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh

# install Composer
RUN install-php-extensions @composer

FROM basic as basic__test
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
RUN mkdir t && (cd t && ' . (in_array($phpVersion, ['8.3'], true) ? 'echo \'{}\' > composer.json && composer config platform.php 8.2 && ' : '') . 'composer require phpunit/phpunit) && rm -r t/


FROM basic as node

# install Node JS with npm
RUN ' . ($osName === 'debian' ? 'curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && ' : '') . $genPackageInstallCommand($osName, ['nodejs', ...($osName === 'debian' ? [/* fix nodejs and npm apt config, drop once we migrate to Debian 12/Bookworm */] : ['npm'])])
    . ($osName === 'debian' ? ' && npm install --global npm@latest' : '') . '

FROM node as node__test
RUN npm version
RUN mkdir t && (cd t && npm install mocha) && rm -r t/


FROM node as selenium

# install Selenium
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['openjdk11-jre-headless', 'xvfb', 'ttf-freefont'], 'debian' => ['openjdk-11-jre-headless', 'xvfb', 'fonts-freefont-ttf']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['chromium', 'chromium-chromedriver', 'nss-tools'], 'debian' => ['chromium', 'chromium-driver', 'libnss3-tools']][$osName]) . '

# install Firefox
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['firefox'], 'debian' => ['firefox-esr']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.32.0/geckodriver-v0.32.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN ' . ($osName === 'alpine' ? 'chromium-browser' : 'chromium') . ' --version
RUN firefox --version
';

                $dataDir = __DIR__ . '/data';
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
        uses: actions/checkout@v3

      - name: "Check if files are in-sync"
        run: |
          rm -rf data/
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
        uses: actions/checkout@v3


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
        uses: docker/login-action@v2
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

<a href="https://github.com/' . $githubRepository . '/actions"><img src="https://github.com/' . $githubRepository . '/workflows/CI/badge.svg" alt="Build Status"></a>

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
