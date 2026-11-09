FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd exif \
 && a2enmod headers remoteip \
 && printf "upload_max_filesize=12M\npost_max_size=14M\nmemory_limit=256M\nexpose_php=Off\n" > /usr/local/etc/php/conf.d/app.ini \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && printf '<Directory /var/www/html/data>\n  Require all denied\n</Directory>\n<Directory /var/www/html/uploads>\n  php_admin_flag engine off\n</Directory>\nRemoteIPHeader X-Forwarded-For\nRemoteIPTrustedProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/app.conf \
 && rm -rf /var/lib/apt/lists/*
COPY . /var/www/html/
RUN rm -f /var/www/html/Dockerfile /var/www/html/docker-compose.yml \
 && mkdir -p /var/www/html/data /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html
# Volumes können root gehören – beim Start Rechte setzen
CMD ["sh", "-c", "chown -R www-data:www-data /var/www/html/data /var/www/html/uploads && exec apache2-foreground"]
