FROM nextcloud:latest

# Update package list and install vim
COPY ./estc /var/www/html/estc
RUN chown -R www-data:www-data /var/www/html/estc && chmod -R 755 /var/www/html/estc
RUN apt update && apt install -y vim

# Install and enable mysqli extension
RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN docker-php-ext-enable mysqli


# Clean up apt cache to reduce image size
RUN apt clean && rm -rf /var/lib/apt/lists/*