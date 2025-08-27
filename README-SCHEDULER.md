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
