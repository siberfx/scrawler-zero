#!/bin/bash
# Ubuntu Dependencies Installation Script for Scrawler
# Run this script on your Ubuntu server

set -e

echo "Installing Scrawler dependencies on Ubuntu..."

# Update package list
echo "Updating package list..."
sudo apt update

# Install basic dependencies
echo "Installing basic dependencies..."
sudo apt install -y \
    curl \
    wget \
    unzip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    lsb-release

# Install PHP 8.3+ if not already installed
echo "Checking PHP installation..."
if ! command -v php &> /dev/null; then
    echo "Installing PHP 8.3..."
    sudo add-apt-repository ppa:ondrej/php -y
    sudo apt update
    sudo apt install -y php8.3 php8.3-cli php8.3-common php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip
fi

# Install MongoDB PHP extension
echo "Installing MongoDB PHP extension..."
sudo apt install -y php8.3-dev php8.3-mongodb

# Install Composer if not already installed
if ! command -v composer &> /dev/null; then
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
fi

# Install MongoDB server (optional - if you want local MongoDB)
read -p "Do you want to install MongoDB server locally? (y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Installing MongoDB server..."
    wget -qO - https://www.mongodb.org/static/pgp/server-6.0.asc | sudo apt-key add -
    echo "deb [ arch=amd64,arm64 ] https://repo.mongodb.org/apt/ubuntu focal/mongodb-org/6.0 multiverse" | sudo tee /etc/apt/sources.list.d/mongodb-org-6.0.list
    sudo apt update
    sudo apt install -y mongodb-org
    sudo systemctl start mongod
    sudo systemctl enable mongod
fi

# Install ChromeDriver and Chrome
echo "Installing Google Chrome and ChromeDriver..."
wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | sudo apt-key add -
echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" | sudo tee /etc/apt/sources.list.d/google-chrome.list
sudo apt update
sudo apt install -y google-chrome-stable chromium-chromedriver

# Install additional dependencies for web scraping
echo "Installing additional dependencies..."
sudo apt install -y \
    xvfb \
    libxi6 \
    libgconf-2-4 \
    libnss3 \
    libxss1 \
    libglib2.0-0 \
    libxrandr2 \
    libasound2 \
    libpangocairo-1.0-0 \
    libatk1.0-0 \
    libcairo-gobject2 \
    libgtk-3-0 \
    libgdk-pixbuf2.0-0

# Verify installations
echo "Verifying installations..."
echo "PHP version: $(php --version | head -n1)"
echo "Composer version: $(composer --version)"
echo "ChromeDriver version: $(chromedriver --version)"
echo "Chrome version: $(google-chrome --version)"

# Set up project permissions
echo "Setting up project permissions..."
PROJECT_DIR="/home/ploi/scrawler.opub.nl"
if [ -d "$PROJECT_DIR" ]; then
    sudo chown -R $USER:$USER "$PROJECT_DIR"
    chmod -R 755 "$PROJECT_DIR"
    
    # Create logs directory
    mkdir -p "$PROJECT_DIR/logs"
    chmod 755 "$PROJECT_DIR/logs"
    
    echo "Project permissions set for: $PROJECT_DIR"
fi

echo "Installation complete!"
echo ""
echo "Next steps:"
echo "1. Navigate to your project directory"
echo "2. Run: composer install"
echo "3. Copy .env.example to .env and configure"
echo "4. Test ChromeDriver: php scrawler openoverheid:crawl --page=1"
