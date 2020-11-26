#!/usr/bin/env bash

# Get composer
# Locked to version 1.X.X to preserve PHP 5.6 compatibility
EXPECTED_SIGNATURE="6fa00eba5103ce6750f94f87af8356e12cc45d5bbb11a140533790cf60725f1c"
php -r "copy('https://getcomposer.org/download/1.10.17/composer.phar', 'composer.phar');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha256', 'composer.phar');")"

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
then
    >&2 echo 'ERROR: Invalid installer signature'
    rm composer-setup.php
    exit 1
fi

# Run composer
php composer-setup.php
rm composer-setup.php

# Remove vendor directory
rm -rf vendor
rm -rf build

# Run composer install
php composer.phar install

# Require and run php-scoper
# Locked to version compatible with version of composer
php composer.phar global require humbug/php-scoper "0.12.3"
COMPOSER_BIN_DIR="$(composer global config bin-dir --absolute)"
"$COMPOSER_BIN_DIR"/php-scoper add-prefix

# Dump composer autoload for build folder
php composer.phar dump-autoload --working-dir build --classmap-authoritative

# Remove composer
rm composer.phar

# Remove existing build zip file
rm woocommerce-onpay.zip

# Rsync contents of folder to new directory that we will use for the build
rsync -Rr ./* ./woocommerce-onpay

# Remove directories and files from newly created directory, that we won't need in final build
rm -rf ./woocommerce-onpay/vendor
rm ./woocommerce-onpay/build.sh
rm ./woocommerce-onpay/composer.json
rm ./woocommerce-onpay/composer.lock

# Replace require file with build version
rm ./woocommerce-onpay/require.php
mv ./woocommerce-onpay/require_build.php ./woocommerce-onpay/require.php

# Zip contents of newly created directory
zip -r woocommerce-onpay.zip ./woocommerce-onpay

# Clean up
rm -rf woocommerce-onpay
rm -rf build