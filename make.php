<?php

$phpVersions = ['7.2', '7.3', '7.4', '8.0', '8.1'];
$osNames = ['alpine', 'debian'];
$targetNames = ['base', 'node', 'selenium'];

$aliasesPhpVersions = [
    '7.4' => ['7.x'],
    '8.0' => ['8.x', 'latest'],
];
$defaultOs = 'alpine';

$createFullName = function (string $imageName, string $targetName): string {
    return $imageName . ($targetName === 'base' ? '' : '-' . $targetName);
};

$genImageTags = function(string $imageName) use ($aliasesPhpVersions, $defaultOs): array {
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

$imageNames = [];
foreach ($osNames as $osName) {
    foreach ($phpVersions as $phpVersion) {
        $genPackageInstallCommand = function (array $packages) use ($osName) {
            $and = ' \\' . "\n" . '    && ';

            if ($osName === 'alpine') {
                return 'apk update' . $and . 'apk add ' . implode(' ', $packages) . $and . 'rm -rf /var/cache/apk/*';
            }

            return 'apt-get -y update' . $and . 'apt-get -y install ' . implode(' ', $packages) . $and . 'apt-get -y autoremove && apt-get clean';
        };

        $dockerFile = 'FROM php:' . $phpVersion . ($phpVersion === '8.1' ? '-rc' : '') . '-' . ['alpine' => 'alpine', 'debian' => 'buster'][$osName] . ' as base

# install basic system tools
RUN ' . ($osName === 'debian' ? '(seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \\' . "\n" . '    && ' : '')
    . $genPackageInstallCommand(array_merge(
        ['bash', 'git', 'make', 'unzip', 'gnupg'],
        ['alpine' => ['coreutils'], 'debian' => ['apt-utils', 'apt-transport-https', 'netcat']][$osName]
    )) . ' \
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
RUN ' . $genPackageInstallCommand(['nodejs', 'npm'])
    . ($osName === 'debian' ? ' && npm install --global npm@latest' : '') . '


FROM node as selenium

# install Selenium
RUN ' . $genPackageInstallCommand(['alpine' => ['openjdk11-jre-headless', 'xvfb', 'ttf-freefont'], 'debian' => ['openjdk-11-jre-headless', 'xvfb', 'fonts-freefont-ttf']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN ' . $genPackageInstallCommand(['alpine' => ['chromium', 'chromium-chromedriver'], 'debian' => ['chromium', 'chromium-driver']][$osName]) . '

# install Firefox
RUN ' . $genPackageInstallCommand(['alpine' => ['firefox'], 'debian' => ['firefox-esr']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver
';

        $dataDir = __DIR__ . '/data';
        $imageName = $phpVersion . '-' . $osName;
        $imageNames[] = $imageName;

        if (!is_dir($dataDir)) {
            mkdir($dataDir);
        }
        if (!is_dir($dataDir . '/' . $imageName)) {
            mkdir($dataDir . '/' . $imageName);
        }
        file_put_contents($dataDir . '/' . $imageName . '/Dockerfile', $dockerFile);
    }
}


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
        uses: actions/checkout@v2

      - name: "Check if files are in-sync"
        run: |
          rm -rf data/
          php make.php
          git diff --exit-code

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
'. implode("\n", array_map(function ($imageName) {
    return '          - "' . $imageName . '"';
}, $imageNames)) . '
    steps:
      - name: Checkout
        uses: actions/checkout@v2
' . implode("\n", array_map(function ($targetName) {
    return '
      - name: \'Target "' . $targetName . ' - Build Dockerfile\'
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          sed -i \'s~^[ \t]*~~\' data/${{ matrix.imageName }}/Dockerfile
          && (' . implode("\n" . '          || ', array_fill(0, 2, 'docker build -f data/${{ matrix.imageName }}/Dockerfile --target "' . $targetName . '" -t ci-target:' . $targetName . ' ./')) . ')

      - name: \'Target "' . $targetName . '" - Display layer sizes\'
        run: >-
          docker history --no-trunc --format "table {{.CreatedSince}}\t{{.Size}}\t{{.CreatedBy}}" $(docker images --no-trunc --format=\'{{.ID}}\' | head -1)
          && docker images --no-trunc --format "Total size: {{.Size}}\t{{.ID}}" | grep $(docker images --no-trunc --format=\'{{.ID}}\' | head -1) | cut -f1';
}, $targetNames)) .'

      - name: Login to registry
        uses: docker/login-action@v1
        with:
          registry: ${{ env.REGISTRY_NAME }}
          username: ${{ github.repository_owner }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: \'Push tags to registry\'
        if: github.ref == \'refs/heads/master\'
        run: >-
          ' . implode("\n" . '          && ', array_map(function ($imageName) use ($targetNames, $genImageTags, $createFullName) {
    return 'if [ "${{ matrix.imageName }}" == "' . $imageName . '" ]; then
          ' . implode("\n" . '          && ', array_merge(...array_map(function ($targetName) use ($genImageTags, $createFullName, $imageName) {
        return array_map(function ($imageTag) use ($targetName) {
            return 'docker tag "ci-target:' . $targetName . '" "$REGISTRY_IMAGE_NAME:' . $imageTag . '" && docker push "$REGISTRY_IMAGE_NAME:' . $imageTag . '"';
        }, $genImageTags($createFullName($imageName, $targetName)));
    }, $targetNames))) . '
          ; fi';
}, $imageNames)) . '
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
        return array_map(function($imageName) use ($createFullName, $targetName) {
            return $createFullName($imageName, $targetName);
        }, $imageNames);
    }, $targetNames)
))).'

## Running Locally

Run `php make.php` to regenerate Dockerfiles.
';
file_put_contents(__DIR__ . '/README.md', $readmeFile);
