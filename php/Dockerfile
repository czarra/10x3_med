# Pin do konkretnego digestu — `php:8.5-apache` to ruchomy tag i jego kolejne
# wydania psuły build (raz brakiem package-lock w Railpacku, raz podwójnym MPM).
# Ten digest jest zweryfikowany: mods-enabled ma sam mpm_prefork, a zbudowany
# z niego obraz prod odpowiada 200 na /login. Podbijaj świadomie, nie automatem.
FROM php:8.5-apache@sha256:609de4eac65a03f20975441c9c3f313811d785575f0d02413c630753ab5c5532 AS base

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

# mod_php wymaga MPM prefork. Świeże php:8.5-apache bywa z włączonym również
# mpm_event/mpm_worker (regresja upstreamu po aktualizacji apache2 w Debianie),
# co wywala starcie: "AH00534: apache2: Configuration error: More than one MPM
# loaded." — wymuszamy sam prefork.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork

# Konfiguracja VirtualHost dla środowiska deweloperskiego,
# kierująca ruch do publicznego katalogu Symfony
RUN echo '<VirtualHost *:80>\n    ServerName localhost\n    DocumentRoot /var/www/med/public\n    <Directory /var/www/med/public>\n        AllowOverride All\n        Require all granted\n        FallbackResource /index.php\n    </Directory>\n</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# 5. Ustawienie katalogu roboczego
WORKDIR /var/www/med

# 6. Wystawienie portu
EXPOSE 80

# 7. Domyślna komenda startowa
CMD ["apache2-foreground"]

# --- dev stage --------------------------------------------------------------
# Co buduje `docker compose` (via `target: dev`). Nic nie dodaje — kod aplikacji
# i vendor/ przychodzą z bind-mounta `.:/var/www/med`, a `run-dev.sh` sam robi
# `composer install` w kontenerze. Dziedziczy CMD ["apache2-foreground"] z base.
FROM base AS dev

# --- prod stage (ostatni — to buduje Railway) ------------------------------
# Samowystarczalny obraz: kod aplikacji + vendor/ (--no-dev) wypieczone w środku.
# Bez ENTRYPOINT — logika startu Railway żyje w railway-start.sh, uruchamianym
# wyłącznie przez `railway.json` startCommand, nie przez CMD obrazu.
FROM base AS prod

# Warstwa vendor/ cache'owana osobno od kodu aplikacji.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && mkdir -p var && chown -R www-data:www-data var

COPY php/railway-start.sh /usr/local/bin/railway-start.sh
RUN chmod +x /usr/local/bin/railway-start.sh
