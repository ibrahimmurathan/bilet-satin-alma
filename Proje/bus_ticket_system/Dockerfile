# PHP 8.2 + Apache (Debian bookworm tabanlı)
FROM php:8.2-apache-bookworm

# Apt'i sağlamlaştır + gerekli paketler ve PHP eklentileri
RUN set -eux; \
    apt-get update -o Acquire::Retries=5; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        sqlite3 \
        libsqlite3-dev \
        unzip; \
    update-ca-certificates; \
    docker-php-ext-install pdo pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*

# mod_rewrite
RUN a2enmod rewrite

# Apache vhost (aynı klasördeki dosya)
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

# Proje dosyaları (compose ile mount etsen de, mount olmadan da çalışsın diye kopyalıyoruz)
COPY . /var/www/html

# İzinler
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

WORKDIR /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
