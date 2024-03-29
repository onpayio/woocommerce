#!/usr/bin/env bash

# Get composer
EXPECTED_SIGNATURE="566a6d1cf4be1cc3ac882d2a2a13817ffae54e60f5aa7c9137434810a5809ffc"
php -r "copy('https://getcomposer.org/download/2.5.5/composer.phar', 'composer.phar');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha256', 'composer.phar');")"

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
then
    >&2 echo 'ERROR: Invalid installer signature'
    rm composer.phar
    exit 1
fi

# Remove vendor directory
rm -rf vendor
rm -rf build

# Run composer install
php composer.phar install

# Require and run php-scoper
php composer.phar global require humbug/php-scoper
COMPOSER_BIN_DIR="$(composer global config bin-dir --absolute)"
"$COMPOSER_BIN_DIR"/php-scoper add-prefix

# Dump composer autoload for build folder
php composer.phar dump-autoload --working-dir build --classmap-authoritative

# Search and replace to set the prefix of the Composer Namespace properly, 
# Since Composer skips this when dumping with --classmap-authoritative
sed -i -e "s|'Composer\\\\\\\\InstalledVersions'|'WoocommerceOnpay\\\\\\\\Composer\\\\\\\\InstalledVersions'|g" build/vendor/composer/autoload_classmap.php build/vendor/composer/autoload_static.php 

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
rm ./woocommerce-onpay/scoper.inc.php

# Replace require file with build version
rm ./woocommerce-onpay/require.php
mv ./woocommerce-onpay/require_build.php ./woocommerce-onpay/require.php

# Zip contents of newly created directory
zip -r woocommerce-onpay.zip ./woocommerce-onpay

# Clean up
rm -rf woocommerce-onpay
rm -rf build