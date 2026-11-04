FROM php:8.3-apache
COPY . /var/www/html/
ENV CRM_DATOS=/var/www/datos
RUN mkdir -p /var/www/datos && chown -R www-data:www-data /var/www/datos \
    && rm -rf /var/www/html/datos/*.sqlite* /var/www/html/datos/*.log /var/www/html/datos/sesiones
VOLUME ["/var/www/datos"]
EXPOSE 80
