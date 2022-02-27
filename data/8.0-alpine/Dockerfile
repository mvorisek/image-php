FROM php:8.0-alpine as base

# install basic system tools
RUN rm -rf /var/cache/apk/* && echo 'xx_base'
# && apk update && apk add tree && rm -rf /var/cache/apk/*
RUN echo 'xx_uu'

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN IPE_GD_WITHOUTAVIF=1 install-php-extensions bcmath
RUN apk add git
RUN apk add ghostscript && rm /usr/bin/gs && rm /usr/lib/libgs.so.9.55 && rm -r /usr/share/ghostscript
RUN install-php-extensions imagick

FROM base as base__test
COPY test.php ./
COPY test.php ./dupl
RUN echo 'ooooooo' > a.txt && echo 'ooooooo' > b.txt
RUN echo 'ooooooo' > y.txt && echo 'ooooooo' > z.txt
RUN echo 'xx_test'
