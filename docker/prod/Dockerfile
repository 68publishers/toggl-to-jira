FROM php:8.1.8-cli-alpine3.16 AS app

MAINTAINER support@68publishers.io

CMD ["/bin/sh"]
WORKDIR /var/www/html

COPY --chown=1000:1000 bin /var/www/html/bin
COPY --chown=1000:1000 config /var/www/html/config
COPY --chown=1000:1000 src /var/www/html/src
COPY --chown=1000:1000 var /var/www/html/var
COPY --chown=1000:1000 vendor /var/www/html/vendor

RUN touch /var/www/html/.env

#USER app
CMD tail -f /dev/null
