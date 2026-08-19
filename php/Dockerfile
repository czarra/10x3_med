FROM php:8.5-apache

# 1. Instalacja zależności systemowych
RUN apt-get update \
    && apt-get install -y zlib1g-dev g++ git libicu-dev zip libzip-dev zip unzip libpq-dev libpng-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalacja rozszerzeń PHP
RUN docker-php-ext-install intl pdo_pgsql gd zip \
    && pecl install apcu \
    && docker-php-ext-enable apcu

# 3. Instalacja Composera
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 4. Konfiguracja Apache dla Symfony
RUN a2enmod rewrite

# Konfiguracja VirtualHost dla środowiska deweloperskiego,
# kierująca ruch do publicznego katalogu Symfony
RUN echo '<VirtualHost *:80>\n    ServerName localhost\n    DocumentRoot /var/www/med/public\n    <Directory /var/www/med/public>\n        AllowOverride All\n        Require all granted\n        FallbackResource /index.php\n    </Directory>\n</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# 5. Ustawienie katalogu roboczego
WORKDIR /var/www/med

# 6. Wystawienie portu
EXPOSE 80

# 7. Domyślna komenda startowa
CMD ["apache2-foreground"]
