# Mzigo.online — production image (PHP 8.2 + Apache + MySQL client)
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

WORKDIR /var/www/html

# Application code (see ../.dockerignore)
COPY . /var/www/html/

# Document root is the project root; .htaccess provides clean URLs and the 404 page.
RUN printf '%s\n' \
      '<Directory /var/www/html>' \
      '    Options -Indexes +FollowSymLinks' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      'Header always set X-Content-Type-Options "nosniff"' \
      'Header always set X-Frame-Options "SAMEORIGIN"' \
      'Header always set Referrer-Policy "strict-origin-when-cross-origin"' \
      'Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"' \
      'Header always set Cross-Origin-Opener-Policy "same-origin"' \
      > /etc/apache2/conf-available/mzigo.conf \
    && a2enconf mzigo \
    && mkdir -p _server/logs \
    && chown -R www-data:www-data _server/logs \
    && chmod +x deploy/docker-entrypoint.sh

ENV APP_ENV=production
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1/api/health.php') === false ? 1 : 0);"

ENTRYPOINT ["/var/www/html/deploy/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
