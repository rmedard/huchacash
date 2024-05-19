ARG BASE_IMAGE
FROM $BASE_IMAGE

ENV PHP_VERSION=8.2
RUN \
    sudo apt-get update && apt-get install -y libz-dev php-dev php-pear && \
    pecl install grpc && \
    pecl install protobuf && \
    echo "extension=grpc.so" > /etc/php/8.2/cli/conf.d/grpc.ini && \
    echo "extension=protobuf.so" > /etc/php/8.2/cli/conf.d/protobuf.ini  && \
    echo "extension=grpc.so" > /etc/php/8.2/fpm/conf.d/grpc.ini  && \
    echo "extension=protobuf.so" > /etc/php/8.2/fpm/conf.d/protobuf.ini
RUN chmod 777 /run/php
