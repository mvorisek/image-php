#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Mvorisek\Docker\ImagePhp;

// docker run --rm -v "$(git rev-parse --show-toplevel)":/h php:latest /h/make.php

$phpVersionsFromSource = [
    '7.4' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-7\.4\.[0-9]+',
        'forkDlPhpVersion' => '7.4', 'forkDlOsName' => ['alpine' => 'alpine3.16', 'debian' => 'bullseye'], 'forkDlRepoCommit' => '7388e44e40',
    ],
    '8.0' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.0\.[0-9]+',
        'forkDlPhpVersion' => '8.0', 'forkDlOsName' => ['alpine' => 'alpine3.16', 'debian' => 'bullseye'], 'forkDlRepoCommit' => '4c0c395658',
    ],
    '8.1' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.1\.[0-9]+',
        'forkDlPhpVersion' => '8.1', 'forkDlOsName' => ['alpine' => 'alpine3.19', 'debian' => 'bookworm'], 'forkDlRepoCommit' => '5c037abaf9',
    ],
    '8.2' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.2\.[0-9]+',
        'forkDlPhpVersion' => '8.2', 'forkDlOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm'],
    ],
    '8.3' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.3\.[0-9]+',
        'forkDlPhpVersion' => '8.3', 'forkDlOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
    '8.4' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.4\.[0-9]+',
        'forkDlPhpVersion' => '8.4', 'forkDlOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
    '8.5' => [
        'repo' => 'https://github.com/php/php-src.git', 'branchRegex' => 'refs/tags/PHP-8\.5\.[0-9]+',
        'forkDlPhpVersion' => '8.5', 'forkDlOsName' => ['alpine' => 'alpine3.22', 'debian' => 'bookworm']
    ],
];
$osNames = ['alpine', 'debian'];
$targetNames = ['basic', 'node', 'selenium'];

$aliasesPhpVersions = [
    '8.4' => ['latest'],
];
$defaultOs = 'alpine';

class DockerfileGenerator
{
    /**
     * @param 'alpine'|'debian' $osName
     */
    public function __construct(
        public string $osName,
        public string $baseImage,
        public ?string $target = null
    ) {}

    /**
     * @return list<string>
     */
    public function makeBasePackages(): array
    {
        $res = [
            '*upgrade*',
            'bash',
            'git',
            'make',
            'unzip',
            'gnupg',
            'ca-certificates'
        ];

        if ($this->osName === 'alpine') {
            $res[] = 'coreutils';
        } else {
            $res[] = 'apt-utils';
            $res[] = 'apt-transport-https';
            $res[] = 'netcat-traditional';
        }

        return $res;
    }

    public function makePackageInstallCommand(array $packages): string
    {
        $packagesWithoutStar = array_diff($packages, ['*upgrade*']);
        $upgrade = $packagesWithoutStar !== $packages;

        $and = " \\\n"
            . '    && ';

        return $this->osName === 'alpine'
            ? 'apk update'
                . ($upgrade ? $and . 'apk upgrade' : '')
                . $and . 'apk add ' . implode(' ', $packagesWithoutStar)
                . $and . 'rm -rf /var/cache/apk/*'
            : 'apt-get update'
                . ($upgrade ? $and . 'apt-get -y upgrade' : '')
                . $and . 'apt-get -y install ' . implode(' ', $packagesWithoutStar)
                . $and . 'apt-get purge -y --auto-remove && apt-get clean && rm -rf /var/lib/apt/lists/*';
    }

    public function makeFile(): string
    {
        return 'FROM ' . $this->baseImage . ($this->target !== null ? ' AS ' . $this->target : '')
            . "\n"
            . "\n"
            . '# install basic system tools'
            . "\n"
            . 'RUN ' . (
                $this->osName === 'debian'
                ? '(seq 1 8 | xargs -I{} mkdir -p /usr/share/man/man{})'
                    . " \\\n"
                    . '    && '
                : ''
            )
            . $this->makePackageInstallCommand($this->makeBasePackages())
            . " \\\n"
            . '    && git config --system --add url."https://github.com/".insteadOf "git@github.com:"'
            . " \\\n"
            . '    && git config --system --add url."https://github.com/".insteadOf "ssh://git@github.com/"'
            . " \\\n"
            // see https://github.com/actions/checkout/issues/766, remove once fixed officially
            . '    # fix git repository directory is owned by someone else for Github Actions'
            . "\n"
            . '    && { echo \'#!/bin/sh\'; echo \'if [ -n "$GITHUB_WORKSPACE" ] && [ "$(id -u)" -eq 0 ]; then\'; echo \'    (cd / && /usr/bin/git config --global --add safe.directory "$GITHUB_WORKSPACE")\'; echo \'fi\'; echo \'/usr/bin/git "$@"\'; } > /usr/local/bin/git && chmod +x /usr/local/bin/git'
            . "\n";
    }
}

class PhpDockerfileGenerator extends DockerfileGenerator
{
    /**
     * @param 'alpine'|'debian'                                                                                                   $osName
     * @param array{repo: string, branchRegex: string, forkDlPhpVersion: string, forkDlOsName: string, forkDlRepoCommit?: string} $phpSource
     */
    public function __construct(
        public string $osName,
        public string $phpVersion,
        public bool $isDebug,
        public bool $isTs,
        public array $phpSource
    ) {
        parent::__construct($this->osName, 'ci-target:base', 'basic');
    }

    /**
     * @return list<string>
     */
    public function makeBasePackages(): array
    {
        $res = parent::makeBasePackages();

        if ($this->isDebug) {
            $res[] = 'gdb';
        }

        return $res;
    }

    public function makeFile(): string
    {
        $helperGenerator = new DockerfileGenerator('alpine', 'alpine:latest', 'patched-dlphp');

        $phpsrcBranchCmd = '$(git ls-remote \'' . $this->phpSource['repo'] . '\' | grep -iE \'\s' . $this->phpSource['branchRegex'] . '$\' | sort -k2 -V | tee /dev/stderr | awk \'END{print $NF}\' | sed -E \'s~^refs/[^/]+/~~\')';
        $phpsrcCommitCmd = '$(git rev-parse HEAD)';

        $res = $helperGenerator->makeFile()
            . "\n"
            . 'RUN git clone --depth 1 \'' . $this->phpSource['repo'] . '\' -b "' . $phpsrcBranchCmd . '" phpsrc'
            . "\n"
            . 'RUN git clone https://github.com/docker-library/php.git dlphp'
            . "\n"
            . 'RUN ' . $helperGenerator->makePackageInstallCommand(['bison', 're2c', 'tar', 'xz', 'jq', 'gawk', 'autoconf'])
            . "\n"
            . 'COPY patches patches'
            . "\n"
            . 'WORKDIR phpsrc'
            . "\n"
            . 'RUN git checkout -B master'
            . "\n"
            . (
                in_array($this->phpVersion, ['7.4', '8.0'], true)
                    ? 'RUN git apply -v ../patches/fix-pdo_oci-bug60994--php74-80.patch && git -c user.name="a" -c user.email="a@a" commit -am "Fix pdo_oci ext NCLOB read - https://github.com/php/php-src/pull/8018"'
                        . "\n"
                    : (
                        $this->osName === 'alpine' && in_array($this->phpVersion, ['7.4', '8.0', '8.1', '8.2'], true)
                            ? 'RUN sed -E \'s~#if HAVE_OCILOBREAD2$~#if 1~\' -i ext/pdo_oci/oci_statement.c && git -c user.name="a" -c user.email="a@a" commit -am "Fix pdo_oci ext NCLOB read for Alpine - https://github.com/php/php-src/issues/8197"'
                                . "\n"
                        : ''
                    )
            )
            . 'RUN scripts/dev/makedist > /dev/null && mv php-master-*.tar.xz php.tar.xz'
            . "\n"
            . 'RUN git add . -N && git diff --diff-filter=d "' . $phpsrcCommitCmd . '"'
            . "\n"
            . 'WORKDIR ../dlphp'
            . "\n"
            . (
                isset($this->phpSource['forkDlRepoCommit'])
                    ? 'RUN git checkout ' . $this->phpSource['forkDlRepoCommit']
                        . "\n"
                    : ''
            )
            . 'RUN rm -r [0-9].[0-9]*/ && sed -E \'s~( // )error\("missing GPG keys for " \+ env\.version\)~\1["x"]~\' -i Dockerfile-linux.template'
            . "\n"
            . 'RUN echo \'{ "' . $this->phpSource['forkDlPhpVersion'] . '": { "url": "x", "variants": [ "' . $this->phpSource['forkDlOsName'] . '/' . ($this->isTs ? 'zts' : 'cli') . '" ], "version": "' . preg_replace('~-rc\.$~', '.0RC', $this->phpSource['forkDlPhpVersion'] . '.') . '99" } }\' > versions.json'
            . "\n"
            . (
                in_array($this->phpVersion, ['7.4'], true)
                    ? 'RUN git apply -v ../patches/fix-dlphp-strip-gh1280--php74.patch'
                        . "\n"
                    : ''
            )
            . (
                in_array($this->phpVersion, ['7.4', '8.0'], true)
                    ? ''
                    : "\n" . 'RUN git apply -v ../patches/revert-dlphp-gh1600-require-asc-url.patch'
                        . "\n"
            )
            . 'RUN ' . ($this->isDebug ? 'DOCKER_PHP_ENABLE_DEBUG=1 ' : '') . './apply-templates.sh'
            . "\n"
            . 'RUN mv ' . $this->phpSource['forkDlPhpVersion'] . '/' . $this->phpSource['forkDlOsName'] . '/' . ($this->isTs ? 'zts' : 'cli') . '/ img'
            . "\n"
            . 'WORKDIR img'
            . "\n"
            . 'RUN mv ../../phpsrc/php.tar.xz .'
            . "\n"
            . 'RUN sed -E \'s~^(ENV PHP_VERSION[ =]).*~\1CUSTOM--\'"' . $phpsrcBranchCmd . '--' . $phpsrcCommitCmd . '"\'~\' -i Dockerfile'
            . "\n"
            . 'RUN sed -E \'s~^(ENV PHP_URL[ =]).*~COPY php.tar.xz /usr/src/~\' -i Dockerfile'
            . "\n"
            . 'RUN sed -E \'s~^(ENV (GPG_KEYS|PHP_URL|PHP_ASC_URL|PHP_SHA256)[ =]).*~~\' -i Dockerfile'
            . "\n"
            . 'RUN sed -E \'s~-n "\$(PHP_SHA256|PHP_ASC_URL)"~-n ""~\' -i Dockerfile'
            . "\n"
            . 'RUN sed -E \'s~curl -fsSL -o php.tar.xz .*; ~~\' -i Dockerfile'
            . "\n"
            . (
                $this->osName === 'debian'
                    ? ''
                    : 'RUN sed -E \'s~(--with-curl.*)( \\\\)~\1 --enable-embed\2~\' -i Dockerfile'
                        . "\n"
            )
            . (
                $this->phpSource['forkDlOsName'] === 'bullseye'
                    ? 'RUN sed -E \'s~apt-get update;~sed -i "s/deb.debian.org/archive.debian.org/" /etc/apt/sources.list; \0~\' -i Dockerfile'
                        . "\n"
                    : ''
            )
            . (
                in_array($this->phpVersion, ['7.4'], true) && $this->isDebug
                    ? 'RUN sed -E \'s~(--with-curl.*)( \\\\)~\1 --enable-debug\2~\' -i Dockerfile'
                        . "\n"
                    : ''
            )
            . 'RUN git add . -N && git diff --diff-filter=d'
            . "\n";


        $res .= "\n"
            . "\n"
            . parent::makeFile();

        return $res
            . "\n"
            . '# install common PHP extensions'
            . "\n"
            . 'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/'
            . "\n"
            . (
                in_array($this->phpVersion, ['8.5'], true)
                ? 'COPY ../../patches/fix-pdo_sqlsrv-package.xml fix-pdo_sqlsrv-package.xml'
                    . "\n"
                    . 'RUN git clone https://github.com/microsoft/msphpsql.git -b dev pdo_sqlsrv'
                    . " \\\n"
                    . '    && cd pdo_sqlsrv && git reset --hard b3e4bf2944 && rm -r .git'
                    . " \\\n"
                    . '    && cp -r source/shared source/pdo_sqlsrv && cp ../fix-pdo_sqlsrv-package.xml source/pdo_sqlsrv/package.xml'
                    . "\n"
                : ''
            )
            . (
                in_array($this->phpVersion, ['8.5'], true)
                    ? 'RUN git clone --recurse-submodules https://github.com/phpredis/phpredis.git -b develop phpredis'
                        . " \\\n"
                        . '    && cd phpredis && git reset --hard 8be2306e4f && rm -r .git'
                        . "\n"
                    : ''
            )
            . (
                in_array($this->phpVersion, ['8.5'], true)
                ? 'RUN git clone https://github.com/xdebug/xdebug.git -b master xdebug'
                    . " \\\n"
                    . '    && cd xdebug && git reset --hard af9280fff4 && rm -r .git'
                    . " \\\n"
                    . '    && sed -E \'s~(<max>)[0-9]+.[0-9]+(.99</max>)~\199.99\2~\' -i package.xml && sed -E \'s~(if test "\$PHP_XDEBUG_FOUND_VERNUM" -ge ")[0-9]+(00"; then)~\19999\2~\' -i config.m4'
                    . "\n"
                : ''
            )
            . 'RUN IPE_ICU_EN_ONLY=1 install-php-extensions'
            . " \\\n"
            . '    ' . implode(' \\' . "\n" . '    ', [
                'bcmath',
                'exif',
                'gd',
                'gmp',
                in_array($this->phpVersion, ['8.5'], true) ? 'igbinary/igbinary@2e0788825c' : 'igbinary', // TODO waiting for merge https://github.com/igbinary/igbinary/pull/408
                in_array($this->phpVersion, ['8.5'], true) ? 'Imagick/imagick@45adfb7b1e' : 'imagick', // TODO waiting for 3.8.1 release https://github.com/Imagick/imagick/pull/741
                'intl',
                'mysqli',
                in_array($this->phpVersion, ['7.4', '8.0', '8.1'], true) ? 'oci8' : 'php/pecl-database-oci8@e4c20008b0',
                ...(in_array($this->phpVersion, ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'], true) ? ['opcache'] : []), // https://wiki.php.net/rfc/make_opcache_required
                'pcntl',
                'pdo_mysql',
                in_array($this->phpVersion, ['7.4', '8.0', '8.1', '8.2'], true) ? 'pdo_oci' : 'php/pecl-database-pdo_oci@6575b4c5fe',
                'pdo_pgsql',
                in_array($this->phpVersion, ['8.5'], true) ? '$(realpath pdo_sqlsrv/source/pdo_sqlsrv)' : 'pdo_sqlsrv',
                in_array($this->phpVersion, ['8.5'], true) ? '$(realpath phpredis)' : 'redis', // TODO waiting for 6.2.1 release https://github.com/phpredis/phpredis/pull/2676
                'sockets',
                'tidy',
                in_array($this->phpVersion, ['8.5'], true) ? '$(realpath xdebug)' : 'xdebug',
                'xsl',
                'zip',
            ])
            . (
                $this->osName === 'alpine'
                    ? ''
                        . " \\\n"
                        . '    # remove Ghostscript binary, reduce Alpine image size by 23 MB, remove once https://gitlab.alpinelinux.org/alpine/aports/-/issues/13415 is fixed'
                        . "\n"
                        . '    && rm /usr/bin/gs'
                    : ''
            )
            . ''
            . " \\\n"
            . '    # pack Oracle Instant Client libs, reduce image size by 85 MB'
            . "\n"
            . '    && rm /usr/lib/oracle/*/client64/lib/*.jar && tar -czvf /usr/lib/oracle-pack.tar.gz -C / /usr/lib/oracle /usr/local/etc/php/conf.d/xx-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/xx-php-ext-oci8.ini && rm -r /usr/lib/oracle/* /usr/local/etc/php/conf.d/xx-php-ext-pdo_oci.ini /usr/local/etc/php/conf.d/xx-php-ext-oci8.ini && mv /usr/lib/oracle-pack.tar.gz /usr/lib/oracle/pack.tar.gz'
            . " \\\n"
            . '    && { echo \'#!/bin/sh\'; echo \'if [ ! -d /usr/lib/oracle/*/client64 ]; then\'; echo \'    tar -xzf /usr/lib/oracle/pack.tar.gz -C / && rm /usr/lib/oracle/pack.tar.gz\'; echo \'fi\'; } > /usr/lib/oracle/setup.sh && chmod +x /usr/lib/oracle/setup.sh'
            . "\n"
            . "\n"
            . '# install Composer'
            . "\n"
            . 'RUN install-php-extensions @composer'
            . "\n"
            . "\n"
            . 'FROM basic AS basic__test'
            . "\n"
            . 'RUN php --version'
            . "\n"
            . 'COPY test.php ./'
            . "\n"
            . 'RUN (/usr/lib/oracle/setup.sh || true) && php test.php'
            . "\n"
            . 'RUN php -n -r \'exit(' . ($this->isDebug ? '' : '!') . 'ZEND_DEBUG_BUILD ? 0 : 1);\''
            . "\n"
            . 'RUN ' . $this->makePackageInstallCommand(['binutils'])
            . "\n"
            . 'RUN ' . implode(' \\' . "\n" . '    && ', array_map(function ($pathUnescaped) {
                return ($this->isDebug ? '' : '! ') . 'readelf -S ' . $pathUnescaped . ' | grep -q \' \.symtab \'';
            }, [
                '/usr/local/bin/php',
                '/usr/local/lib/libphp' . (in_array($this->phpVersion, ['7.4'], true) ? '7' : '') . '.so',
                '"$(find /usr/local/lib/php/extensions -name bcmath.so)"',
                '"$(find /usr/local/lib/php/extensions -name xdebug.so)"',
            ]))
            . "\n"
            . 'RUN mkdir t && (cd t && composer require symfony/console) && rm -r t/'
            . "\n"
            . "\n"
            . "\n"
            . 'FROM basic AS node'
            . "\n"
            . "\n"
            . '# install Node JS with npm'
            . "\n"
            . 'RUN ' . (
                $this->osName === 'debian'
                    ? 'curl -fsSL https://deb.nodesource.com/setup_lts.x | bash -'
                        . " \\\n"
                        . '    && '
                    : ''
            )
            . $this->makePackageInstallCommand(['nodejs', ...($this->osName === 'debian' ? [/* fix nodejs and npm apt config, drop once we migrate to Debian 12/Bookworm */] : ['npm'])])
            . ($this->osName === 'debian' ? ' && npm install --global npm@latest' : '')
            . "\n"
            . "\n"
            . 'FROM node AS node__test'
            . "\n"
            . 'RUN npm version'
            . "\n"
            . 'RUN mkdir t && (cd t && npm install mocha) && rm -r t/'
            . "\n"
            . "\n"
            . "\n"
            . 'FROM node AS selenium'
            . "\n"
            . "\n"
            . '# install Selenium'
            . "\n"
            . 'RUN ' . $this->makePackageInstallCommand(['alpine' => ['openjdk17-jre-headless', 'xvfb', 'ttf-freefont'], 'debian' => ['openjdk-17-jre-headless', 'xvfb', 'fonts-freefont-ttf']][$this->osName])
            . " \\\n"
            . '    && curl --fail --silent --show-error -L "https://selenium-release.storage.googleapis.com/3.141/selenium-server-standalone-3.141.59.jar" -o /opt/selenium-server-standalone.jar'
            . "\n"
            . "\n"
            . '# install Chrome'
            . "\n"
            . 'RUN ' . $this->makePackageInstallCommand(['alpine' => ['chromium', 'chromium-chromedriver', 'nss-tools'], 'debian' => ['chromium', 'chromium-driver', 'libnss3-tools']][$this->osName])
            . "\n"
            . "\n"
            . '# install Firefox'
            . "\n"
            . 'RUN ' . $this->makePackageInstallCommand(['alpine' => ['firefox'], 'debian' => ['firefox-esr']][$this->osName]) . ''
            . " \\\n"
            . '    && curl --fail --silent --show-error -L "https://github.com/mozilla/geckodriver/releases/download/v0.36.0/geckodriver-v0.36.0-linux64.tar.gz" -o /tmp/geckodriver.tar.gz'
            . " \\\n"
            . '    && tar -C /opt -zxf /tmp/geckodriver.tar.gz && rm /tmp/geckodriver.tar.gz'
            . " \\\n"
            . '    && chmod 755 /opt/geckodriver && ln -s /opt/geckodriver /usr/bin/geckodriver'
            . "\n"
            . "\n"
            . 'FROM selenium AS selenium__test'
            . "\n"
            . 'RUN ' . ($this->osName === 'alpine' ? 'chromium-browser' : 'chromium') . ' --version'
            . "\n"
            . 'RUN firefox --version'
            . "\n";
    }
}

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

                $phpSource = $phpVersionsFromSource[$phpVersion];
                $phpSource['forkDlOsName'] = $phpSource['forkDlOsName'][$osName];
                $generator = new PhpDockerfileGenerator($osName, $phpVersion, $isDebug, $isTs, $phpSource);
                $dockerFile = $generator->makeFile();

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

' . $genBatchedStepCode(fn ($imageNames) => '      - name: \'Build helper image - build\'
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource, $createFullName) {
    return '(' . implode("\n" . '          || ', array_fill(0, 2, 'docker build --target "patched-dlphp" -t "ci-target:patched-dlphp" -f data/' . $imageName . '/Dockerfile ./')) . ')';
})) . '

' . $genBatchedStepCode(fn ($imageNames) => '      - name: \'Build base image - build\'
        # try to build twice to suppress random network issues with Github Actions
        run: >-
          docker cp $(docker create ci-target:patched-dlphp):/dlphp/img patched-dlphp
          && cd patched-dlphp
          && ' . $genRuntimeConditionalCode($imageNames, function ($imageName, $phpVersion, $isTs, $osName) use ($phpVersionsFromSource, $createFullName) {
    $cacheFromImages = [
        'php:' . $phpVersionsFromSource[$phpVersion]['forkDlPhpVersion'] . '-' . ($isTs ? 'zts' : 'cli') . '-' . $phpVersionsFromSource[$phpVersion]['forkDlOsName'][$osName],
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
