#!/bin/bash
# deploy.sh — Run on EC2 to deploy the app

# Install PHP, Nginx, Composer
sudo dnf install -y nginx php php-fpm php-pdo php-mysqlnd php-json unzip

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Copy app files
sudo mkdir -p /var/www/html
sudo cp -r ./* /var/www/html/
cd /var/www/html

# Install AWS SDK
sudo composer require aws/aws-sdk-php

# Set permissions
sudo chown -R nginx:nginx /var/www/html
sudo chmod -R 755 /var/www/html

# Configure Nginx
sudo tee /etc/nginx/conf.d/app.conf > /dev/null <<'EOF'
server {
    listen 80;
    server_name _;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

# Set environment variables
sudo tee /etc/environment >> /dev/null <<EOF
DB_MASTER_HOST=YOUR_RDS_MASTER_ENDPOINT
DB_REPLICA_HOST=YOUR_RDS_REPLICA_ENDPOINT
DB_PASS=YourPassword123!
EOF

# Start services
sudo systemctl enable nginx php-fpm
sudo systemctl restart nginx php-fpm

echo "App deployed! Open http://$(curl -s http://169.254.169.254/latest/meta-data/public-ipv4)"
