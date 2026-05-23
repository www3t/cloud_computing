#!/bin/bash
# init.sh — UserData script for EC2 web server

dnf update -y
dnf install -y nginx

# Get instance metadata
INSTANCE_ID=$(curl -s http://169.254.169.254/latest/meta-data/instance-id)
AZ=$(curl -s http://169.254.169.254/latest/meta-data/placement/availability-zone)
PRIVATE_IP=$(curl -s http://169.254.169.254/latest/meta-data/local-ipv4)

# Create custom index page showing instance info
cat > /usr/share/nginx/html/index.html <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AWS Lab 6 — Auto Scaling</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: white; border-radius: 16px; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); max-width: 520px; width: 100%; text-align: center; }
        h1 { color: #232f3e; font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #888; margin-bottom: 28px; font-size: 14px; }
        .info { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 16px; text-align: left; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .label { color: #888; }
        .value { font-weight: 600; color: #232f3e; }
        .badge { background: #ff9900; color: white; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 700; display: inline-block; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="card">
    <h1>☁️ AWS Lab 6</h1>
    <p class="subtitle">Auto Scaling + Load Balancer</p>
    <span class="badge">✅ Instance Running</span>
    <div class="info">
        <div class="info-row">
            <span class="label">Instance ID</span>
            <span class="value">$INSTANCE_ID</span>
        </div>
        <div class="info-row">
            <span class="label">Availability Zone</span>
            <span class="value">$AZ</span>
        </div>
        <div class="info-row">
            <span class="label">Private IP</span>
            <span class="value">$PRIVATE_IP</span>
        </div>
    </div>
</div>
</body>
</html>
EOF

# Create load-generating endpoint
cat > /usr/share/nginx/html/load.php <<'PHPEOF'
<?php
$seconds = min((int)($_GET['seconds'] ?? 30), 120);
$end = time() + $seconds;
while (time() < $end) {
    $x = 0;
    for ($i = 0; $i < 1000000; $i++) { $x += sqrt($i); }
}
echo json_encode([
    'status' => 'done',
    'duration' => $seconds,
    'instance' => gethostname()
]);
PHPEOF

dnf install -y php php-fpm

# Configure nginx for PHP
cat > /etc/nginx/conf.d/default.conf <<'NGINXEOF'
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    location /load {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index load.php;
        fastcgi_param SCRIPT_FILENAME /usr/share/nginx/html/load.php;
        include fastcgi_params;
    }
}
NGINXEOF

systemctl enable nginx php-fpm
systemctl start nginx php-fpm
