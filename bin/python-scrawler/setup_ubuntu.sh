#!/bin/bash
# setup_ubuntu.sh
# Ubuntu server setup script for MongoDB URL scraper
# Compatible with Ubuntu 20.04, 22.04, 24.04

echo "=========================================="
echo "MongoDB URL Scraper - Ubuntu Setup"
echo "=========================================="

# Check Ubuntu version
echo "Checking Ubuntu version..."
ubuntu_version=$(lsb_release -rs)
echo "Detected Ubuntu version: $ubuntu_version"

# Update system
echo "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# Install Python 3.10+ and pip
echo "Installing Python and pip..."
sudo apt install -y python3 python3-pip python3-venv

# Check Python version
python_version=$(python3 --version)
echo "Installed Python version: $python_version"

# Install system dependencies for Playwright
echo "Installing system dependencies..."
sudo apt install -y \
    libnss3-dev \
    libatk-bridge2.0-dev \
    libdrm2 \
    libxkbcommon0 \
    libgtk-3-dev \
    libgbm-dev \
    libasound2-dev

# Create project directory
echo "Creating project directory..."
mkdir -p ~/mongodb-scraper
cd ~/mongodb-scraper

# Create virtual environment
echo "Creating Python virtual environment..."
python3 -m venv venv
source venv/bin/activate

# Install Python dependencies
echo "Installing Python dependencies..."
pip install --upgrade pip
pip install playwright pymongo

# Install Playwright browsers
echo "Installing Playwright browsers..."
playwright install chromium

# Create systemd service file
echo "Creating systemd service..."
sudo tee /etc/systemd/system/mongodb-scraper.service > /dev/null <<EOF
[Unit]
Description=MongoDB URL Scraper
After=network.target

[Service]
Type=simple
User=$USER
WorkingDirectory=/home/$USER/mongodb-scraper
Environment=PATH=/home/$USER/mongodb-scraper/venv/bin
ExecStart=/home/$USER/mongodb-scraper/venv/bin/python production_runner.py
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

# Create log rotation configuration
echo "Setting up log rotation..."
sudo tee /etc/logrotate.d/mongodb-scraper > /dev/null <<EOF
/home/$USER/mongodb-scraper/mongodb_scraper.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    create 644 $USER $USER
}
EOF

echo "=========================================="
echo "Setup completed!"
echo "=========================================="
echo "Next steps:"
echo "1. Copy your Python files to ~/mongodb-scraper/"
echo "2. Activate virtual environment: source ~/mongodb-scraper/venv/bin/activate"
echo "3. Test the scraper: python production_runner.py"
echo "4. Enable service: sudo systemctl enable mongodb-scraper"
echo "5. Start service: sudo systemctl start mongodb-scraper"
echo "6. Check status: sudo systemctl status mongodb-scraper"
echo "7. View logs: journalctl -u mongodb-scraper -f"
echo "=========================================="
