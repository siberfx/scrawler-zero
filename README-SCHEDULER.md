# Scrawler Scheduler Setup

This document explains how to set up automated scheduling for all Scrawler commands.

## Scheduled Commands

The following commands are configured to run automatically:

### 1. Organizations Crawl
- **Command**: `organizations:crawl`
- **Schedule**: Daily at 2:00 AM
- **Purpose**: Crawls organization data from government websites
- **Log**: `storage/logs/organizations-crawl.log`

### 2. OpenOverheid Crawl
- **Command**: `openoverheid:crawl`
- **Schedule**: Every 6 hours
- **Purpose**: Crawls documents from open.overheid.nl
- **Log**: `storage/logs/openoverheid-crawl.log`

### 3. Organization Details Processing
- **Command**: `organizations:process-details --limit=500`
- **Schedule**: Daily at 3:00 AM (after organizations crawl)
- **Purpose**: Processes detailed organization information
- **Log**: `storage/logs/organizations-process.log`

### 4. Document Processing
- **Command**: `documents:process --limit=50`
- **Schedule**: Every 2 hours
- **Purpose**: Processes downloaded documents and extracts content
- **Log**: `storage/logs/documents-process.log`

### 5. Log Cleanup
- **Schedule**: Weekly on Sundays at 1:00 AM
- **Purpose**: Removes log files older than 30 days

## Setup Instructions

### Prerequisites

Before setting up the scheduler, ensure all Python dependencies are installed:

#### Install Python Requirements

**For Windows:**
```bash
# Navigate to the project directory
cd X:\laragon\www\scrawler\bin\python-scrawler

# Install Python dependencies from requirements.txt
pip install -r requirements.txt

# Alternative: Use pip3 if you have multiple Python versions
pip3 install -r requirements.txt
```

**For Linux/Ubuntu (Recommended - Virtual Environment):**
```bash
# Navigate to the project directory
cd ~/worker.opub.nl/bin/python-scrawler

# Create virtual environment
python3 -m venv venv

# Activate virtual environment
source venv/bin/activate

# Install dependencies
pip install -r requirements.txt

# Install Playwright browsers
playwright install

# Install Playwright system dependencies
sudo playwright install-deps
```

**For Linux/Ubuntu (Alternative Methods):**
```bash
# Method 1: Using pipx (if available)
pipx install -r requirements.txt

# Method 2: Force system-wide installation (NOT RECOMMENDED)
pip install -r requirements.txt --break-system-packages
playwright install
sudo playwright install-deps

# Method 3: Install system packages (if available)
sudo apt update
sudo apt install python3-pymongo python3-requests python3-beautifulsoup4

# Method 4: Manual system dependencies (if playwright install-deps fails)
sudo apt-get install libnspr4 libnss3 libatk1.0-0t64 libatk-bridge2.0-0t64 \
    libatspi2.0-0t64 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 \
    libgbm1 libasound2t64
```

#### Verify Installation

**For Windows:**
```bash
# Test Python MongoDB connection
python mongodb_monitor.py

# Check if all packages are installed
pip list
```

**For Linux/Ubuntu (with virtual environment):**
```bash
# Make sure virtual environment is activated
source venv/bin/activate

# Test Python MongoDB connection
python mongodb_monitor.py

# Test Playwright installation
playwright --version

# Check if all packages are installed
pip list

# Verify browser installation
ls ~/.cache/ms-playwright/

# Deactivate when done (optional)
deactivate
```

**For Linux/Ubuntu (system-wide):**
```bash
# Test Python MongoDB connection
python3 mongodb_monitor.py

# Check system packages
dpkg -l | grep python3-
```

### Option 1: Windows Task Scheduler (Recommended)

1. **Run as Administrator**: Open PowerShell as Administrator
2. **Execute Setup Script**:
   ```powershell
   cd X:\laragon\www\scrawler
   .\setup-scheduler.ps1
   ```
3. **Verify**: Check Windows Task Scheduler for "ScrawlerScheduler" task

### Option 2: Manual Task Scheduler Setup

1. Open Windows Task Scheduler
2. Create Basic Task named "ScrawlerScheduler"
3. Set trigger to run every minute
4. Set action to run: `X:\laragon\www\scrawler\schedule.bat`
5. Configure to run with highest privileges

### Option 3: Manual Execution

Run the scheduler manually:
```bash
php scrawler schedule:run
```

## Monitoring

### Check Scheduler Status
```bash
php scrawler schedule:list
```

### View Logs
- Main scheduler log: `storage/logs/scheduler.log`
- Individual command logs: `storage/logs/[command-name].log`

### Test Individual Commands
```bash
# Test organization crawling
php scrawler organizations:crawl

# Test document processing
php scrawler documents:process --limit=10

# Test organization details processing
php scrawler organizations:process-details --limit=10

# Test OpenOverheid crawling
php scrawler openoverheid:crawl --page=1
```

## Configuration

### Modify Schedules

Edit the `schedule()` method in each command file:
- `app/Commands/CrawlOrganisationsCommand.php`
- `app/Commands/CrawlOpenOverheidCommand.php`
- `app/Commands/ProcessDocumentsCommand.php`
- `app/Commands/ProcessOrganisationDetailsCommand.php`

### Available Schedule Options

```php
$schedule->command('command:name')
    ->everyMinute()           // Every minute
    ->everyFiveMinutes()      // Every 5 minutes
    ->everyTenMinutes()       // Every 10 minutes
    ->everyFifteenMinutes()   // Every 15 minutes
    ->everyThirtyMinutes()    // Every 30 minutes
    ->hourly()                // Every hour
    ->everyTwoHours()         // Every 2 hours
    ->everySixHours()         // Every 6 hours
    ->daily()                 // Daily at midnight
    ->dailyAt('13:00')        // Daily at 1:00 PM
    ->weekly()                // Weekly on Sunday at midnight
    ->monthly()               // Monthly on the 1st at midnight
    ->withoutOverlapping()    // Prevent overlapping executions
    ->runInBackground()       // Run in background
    ->appendOutputTo($path);  // Log output to file
```

## Available Commands

This Laravel Zero application provides several commands for web scraping and data management:

### Python Scraper Commands

#### `python:scraper`
Run Python MongoDB scraper through Laravel with real-time output streaming.

```bash
# Collect URLs from organizations
php scrawler python:scraper collect

# Process collected URLs (with optional limit)
php scrawler python:scraper process --limit=100

# Monitor scraping progress in real-time
php scrawler python:scraper monitor

# Set custom timeout (default: 300 seconds)
php scrawler python:scraper collect --timeout=600
```

### Organization Management Commands

#### `crawl:organisations`
Crawl and collect organization data from OpenOverheid.

```bash
# Crawl all organizations
php scrawler crawl:organisations

# Crawl with specific limit
php scrawler crawl:organisations --limit=50
```

#### `crawl:openoverheid`
Crawl documents from OpenOverheid using Chrome browser automation.

```bash
# Crawl OpenOverheid documents
php scrawler crawl:openoverheid
```

### PID Data Management

#### `fetch:pid-data`
Fetch and process PID (Persistent Identifier) data for organizations.

```bash
# Fetch PID data for all organizations
php scrawler fetch:pid-data

# Fetch with specific limit
php scrawler fetch:pid-data --limit=25
```

### Workflow Commands

#### `workflow:full-scraping`
Execute the complete scraping workflow in sequence.

```bash
# Run full workflow
php scrawler workflow:full-scraping

# Skip URL collection step
php scrawler workflow:full-scraping --skip-collect

# Skip URL processing step  
php scrawler workflow:full-scraping --skip-process

# Skip document conversion step
php scrawler workflow:full-scraping --skip-convert

# Set processing limits
php scrawler workflow:full-scraping --collect-limit=100 --process-limit=50
```

### Data Processing Commands

#### `process:python-urls`
Convert processed Python URLs to Document records.

```bash
# Process all URLs
php scrawler process:python-urls

# Process with limit
php scrawler process:python-urls --limit=100
```

### Utility Commands

#### `test:mongo-connection`
Test MongoDB connection and display database information.

```bash
php scrawler test:mongo-connection
```

### Command Chaining Examples

```bash
# Complete workflow with monitoring
php scrawler workflow:full-scraping && php scrawler python:scraper monitor

# Collect organizations then fetch PID data
php scrawler crawl:organisations --limit=50 && php scrawler fetch:pid-data --limit=50

# Process URLs in batches
php scrawler python:scraper process --limit=25 && php scrawler process:python-urls --limit=25
```

## Troubleshooting

### Common Issues

1. **Task not running**: Check Windows Task Scheduler service is running
2. **Permission errors**: Ensure task runs with appropriate privileges
3. **Path issues**: Verify all paths in batch file are correct
4. **MongoDB connection**: Ensure MongoDB extension is installed and configured

### Debug Mode

Run scheduler with verbose output:
```bash
php scrawler schedule:run --verbose
```

### Check System Requirements

- PHP 8.2+ with MongoDB extension
- MongoDB server running and accessible
- Sufficient disk space for logs and data
- Network access for crawling external sites
