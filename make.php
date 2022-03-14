<?php

$phpVersionsFromSource = [
    '7.3' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-7\.3\.[0-9]+',
        'forkPhpVersion' => '7.3', 'forkOsName' => ['alpine' => 'alpine3.15', 'debian' => 'bullseye']
    ],
    '7.4' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-7\.4\.[0-9]+',
        'forkPhpVersion' => '7.4', 'forkOsName' => ['alpine' => 'alpine3.15', 'debian' => 'bullseye']
    ],
    '8.0' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/heads/PHP-8\.0' /* use tags once PHP 8.0.18 is released */,
        'forkPhpVersion' => '7.4' /* use 8.0 once https://github.com/docker-library/php/pull/1076 is released */, 'forkOsName' => ['alpine' => 'alpine3.15', 'debian' => 'bullseye']
    ],
    '8.1' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/heads/PHP-8\.1' /* use tags once PHP 8.1.5 is released */,
        'forkPhpVersion' => '7.4' /* use 8.1 once https://github.com/docker-library/php/pull/1076 is released */, 'forkOsName' => ['alpine' => 'alpine3.15', 'debian' => 'bullseye']
    ],
];
$phpVersions = [...array_keys($phpVersionsFromSource)];
$osNames = ['alpine', 'debian'];
$targetNames = ['basic', 'node', 'selenium'];

$aliasesPhpVersions = [
    '8.0' => ['latest'],
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
    foreach ($phpVersions as $phpVersion) {
        foreach ([false, true] as $isTs) {
            $dockerFile = 'FROM ' . (isset($phpVersionsFromSource[$phpVersion]) ? 'ci-target:base' : 'php:' . $phpVersion . ($phpVersion === '8.2' ? '-rc' : '') . ($isTs ? '-zts' : '')
                . '-' . ['alpine' => 'alpine', 'debian' => 'bullseye'][$osName]) . ' as basic

# install basic system tools
RUN ' . ($osName === 'debian' ? '(seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{}) \\' . "\n" . '    && ' : '')
    . $genPackageInstallCommand($osName, array_merge(
        ['*upgrade*', 'bash', 'git', 'make', 'unzip', 'gnupg', 'ca-certificates'],
        ['alpine' => ['coreutils'], 'debian' => ['apt-utils', 'apt-transport-https', 'netcat']][$osName]
    )) . ' \
    && git config --system url."https://github.com/".insteadOf "git@github.com:" \
    && git config --system url."https://github.com/".insteadOf "ssh://git@github.com/"

# install common PHP extensions
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_GD_WITHOUTAVIF=1' /* AVIF support needs slow compilation, see https://github.com/mlocati/docker-php-extension-installer/issues/514, remove once the php images are based on Debian 12 */ . ' install-php-extensions \
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
    'xdebug',
    'xsl',
    'zip',
]) . ($osName === 'alpine' ? ' \
    # remove Ghostscript binary, reduce Alpine image size by 23 MB, remove once https://github.com/mlocati/docker-php-extension-installer/issues/519 is fixed
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
RUN test -f /usr/local/lib/libphp' . (in_array($phpVersion, ['7.3', '7.4'], true) ? '7' : '') . '.so
RUN composer diagnose


FROM basic as node

# install Node JS with npm
RUN ' . $genPackageInstallCommand($osName, ['nodejs', 'npm'])
    . ($osName === 'debian' ? ' && npm install --global npm@latest' : '') . '

FROM node as node__test
RUN npm version


FROM node as selenium

# install Selenium
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['openjdk11-jre-headless', 'xvfb', 'ttf-freefont'], 'debian' => ['openjdk-11-jre-headless', 'xvfb', 'fonts-freefont-ttf']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar

# install Chrome
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['chromium', 'chromium-chromedriver', 'nss-tools'], 'debian' => ['chromium', 'chromium-driver', 'libnss3-tools']][$osName]) . '

# install Firefox
RUN ' . $genPackageInstallCommand($osName, ['alpine' => ['firefox'], 'debian' => ['firefox-esr']][$osName]) . ' \
    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.29.1/geckodriver-v0.29.1-linux64.tar.gz" -o /tmp/geckodriver.tar.gz \
    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz \
    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver

FROM selenium as selenium__test
RUN ' . ($osName === 'alpine' ? 'chromium-browser' : 'chromium') . ' --version
RUN firefox --version
';

            $dataDir = __DIR__ . '/data';
            $imageName = $phpVersion . ($isTs ? '-zts' : '') . '-' . $osName;
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
        }, $imageNamesByCode[$code])) . '; then' . "\n" . '          '. $code;
    }, array_keys($imageNamesByCode))) . (count($imageNamesByCode) === 0 ? 'true' : "\n" . '          ; fi');
};

$genRuntimeConditionalCodeFromSourceOnly = function ($imageNames, \Closure $gen) use ($genRuntimeConditionalCode, $phpVersionsFromSource, $phpVersionByImageName): string {
    return $genRuntimeConditionalCode(array_filter($imageNames, function ($imageName) use ($phpVersionsFromSource, $phpVersionByImageName) {
        return isset($phpVersionsFromSource[$phpVersionByImageName[$imageName]]) ? $imageName : null;
    }), $gen);
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
' . implode("\n", array_map(function ($imageName) {
    return '          - "' . $imageName . '"';
}, $imageNames)) . '
    steps:
      - name: Checkout
        uses: actions/checkout@v2

      - name: \'Target "base" - build from php-src\'
        if: ' . (implode(' || ', array_map(function ($imageName) {
            return 'matrix.imageName == \'' . $imageName . '\'';
        }, array_filter($imageNames, function ($imageName) use ($phpVersionsFromSource, $phpVersionByImageName) {
            return isset($phpVersionsFromSource[$phpVersionByImageName[$imageName]]) ? $imageName : null;
        }))) ?: 'false') . '
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          git clone https://github.com/docker-library/php.git dlphp
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'cd dlphp/' . $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '/' . $phpVersionsFromSource[$phpVersion]['forkOsName'][$osName] . '/' . ($isTs ? 'zts' : 'cli') . '/';
}) . '
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'export PHPSRC_BRANCH="$(git ls-remote \'' . $phpVersionsFromSource[$phpVersion]['repo'] . '\' | grep -iE \'\s' . $phpVersionsFromSource[$phpVersion]['branchRegex'] . '$\' | sort -k2 -V | tee /dev/stderr | awk \'END{print $NF}\' | sed -E \'s~^refs/[^/]+/~~\')"';
}) . '
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return 'git clone --depth 1 \'' . $phpVersionsFromSource[$phpVersion]['repo'] . '\' -b "$PHPSRC_BRANCH" php';
}) . '
          && (cd php && git checkout -B master)
          && sudo apt-get -y update && sudo apt-get -y install bison re2c
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    return in_array($phpVersion, ['7.3'], true)
        ? '(cd php && git tag php-1.0 && ./makedist 1.0 > /dev/null && mv php-1.0.tar.xz php.tar.xz)'
        : '(cd php && scripts/dev/makedist > /dev/null && mv php-master-*.tar.xz php.tar.xz)';
}) . '
          && sed -E \'s~^(ENV (GPG_KEYS|PHP_SHA256|PHP_ASC_URL)[ =]).*~~\' -i Dockerfile
          && sed -E \'s~^(ENV PHP_VERSION[ =]).*~\\1CUSTOM~\' -i Dockerfile
          && sed -E \'s~^(ENV PHP_URL[ =]).*~COPY php/php.tar.xz /usr/src/~\' -i Dockerfile
          && sed -E \'s~-n "\$(PHP_SHA256|PHP_ASC_URL)"~-n ""~\' -i Dockerfile
          && sed -E \'s~curl -fsSL -o php.tar.xz .*; ~~\' -i Dockerfile
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    return $osName === 'debian' ? null : 'sed -E \'s~(--with-curl.*)( \\\\)~\\1 --enable-embed\\2~\' -i Dockerfile';
}) . '
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) {
    // remove once https://github.com/docker-library/php/pull/1076 is released
    return in_array($phpVersion, ['7.3', '7.4'], true) ? null : 'sed -E \'s~--enable-maintainer-zts~--enable-zts~\' -i Dockerfile';
}) . '
          && git diff
          && ' . $genRuntimeConditionalCodeFromSourceOnly($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource) {
    $forkImageTag = 'php:' . $phpVersionsFromSource[$phpVersion]['forkPhpVersion'] . '-' . ($isTs ? 'zts' : 'cli') . '-' . $phpVersionsFromSource[$phpVersion]['forkOsName'][$osName];

    return 'docker pull "' . $forkImageTag . '"
          && (' . implode("\n" . '          || ', array_fill(0, 2, 'docker build --cache-from "' . $forkImageTag . '" -t "ci-target:base" ./')) . ')';
}) . '
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
        uses: docker/login-action@v1
        with:
          registry: ${{ env.REGISTRY_NAME }}
          username: ${{ github.repository_owner }}
          password: ${{ secrets.GITHUB_TOKEN }}

      - name: \'Push tags to registry\'
        if: github.ref == \'refs/heads/master\'
        run: >-
          dtp() { docker tag "ci-target:$1" "$REGISTRY_IMAGE_NAME:$2" && docker push "$REGISTRY_IMAGE_NAME:$2"; }
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion) use ($phpVersionsFromSource, $targetNames, $genImageTags, $createFullName) {
    return implode("\n" . '          && ', array_merge(...array_map(function ($targetName) use ($genImageTags, $createFullName, $imageName) {
        return array_map(function ($imageTag) use ($targetName) {
            return 'dtp "' . $targetName . '" "' . $imageTag . '"';
        }, $genImageTags($createFullName($imageName, $targetName)));
    }, [...(isset($phpVersionsFromSource[$phpVersion]) ? ['base'] : []), ...$targetNames])));
}) . '
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
))) . '

## Running Locally

Run `php make.php` to regenerate Dockerfiles.
';
file_put_contents(__DIR__ . '/README.md', $readmeFile);
