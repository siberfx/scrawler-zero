# MongoDB URL Scraper

A Python scraper that collects URLs from open.overheid.nl and stores detailed metadata directly in MongoDB.

## 📋 Overview

This scraper performs two main tasks:
1. **Collect URLs** - Scrapes search result pages to find document URLs
2. **Process URLs** - Extracts detailed metadata from each document and stores it in MongoDB

All data is stored directly in MongoDB - no JSON files needed.

## 📁 Project Files

```
├── mongodb_url_scraper.py    # Main scraper application
├── mongodb_monitor.py        # Progress monitoring tool
├── requirements.txt          # Python dependencies
├── setup_ubuntu.sh          # Ubuntu server setup script
└── README.md                # This file
```

## 🛠️ Installation

### Prerequisites
- Ubuntu 20.04+ or similar Linux distribution
- Python 3.10+
- MongoDB access

### Quick Setup (Ubuntu)

1. **Download and run setup script:**
```bash
chmod +x setup_ubuntu.sh
./setup_ubuntu.sh
```

2. **Copy project files:**
```bash
cp *.py ~/mongodb-scraper/
cp requirements.txt ~/mongodb-scraper/
```

3. **Activate environment and install dependencies:**
```bash
cd ~/mongodb-scraper
source venv/bin/activate
pip install -r requirements.txt
playwright install chromium
```

### Manual Installation

1. **Install system dependencies:**
```bash
sudo apt update
sudo apt install -y python3 python3-pip python3-venv \
    libnss3-dev libatk-bridge2.0-dev libdrm2 libxkbcommon0 \
    libgtk-3-dev libgbm-dev libasound2-dev
```

2. **Create virtual environment:**
```bash
python3 -m venv venv
source venv/bin/activate
```

3. **Install Python packages:**
```bash
pip install playwright>=1.40.0 pymongo>=4.0.0
playwright install chromium
```

## ⚙️ Configuration

Edit the MongoDB connection settings in `mongodb_url_scraper.py`:

```python
MONGO_CONNECTION_STRING = "mongodb://username:password@host:port/"
DB_NAME = "scrawler"
COLLECTION_NAME = "urls"
```

## 🚀 Usage

### 1. Collect URLs from Search Pages

```bash
python mongodb_url_scraper.py collect
```

This will:
- Scrape all search result pages from open.overheid.nl
- Store URLs in MongoDB with `processed: false`
- Skip URLs that already exist in the database

### 2. Process URLs (Extract Metadata)

```bash
# Process all unprocessed URLs
python mongodb_url_scraper.py process

# Process only 10 URLs (for testing)
python mongodb_url_scraper.py process 10
```

This will:
- Get all unprocessed URLs from MongoDB
- Scrape detailed metadata from each URL
- Update MongoDB records with extracted data
- Mark URLs as `processed: true`

### 3. Monitor Progress

In a separate terminal:
```bash
python mongodb_monitor.py
```

Shows real-time statistics:
- Total URLs in database
- Processed vs remaining URLs  
- Progress percentage
- Processing rate and estimated completion time

## 📊 MongoDB Structure

Each URL record contains:

```json
{
  "_id": ObjectId("..."),
  "url": "https://open.overheid.nl/details/oep-...",
  "processed": false,
  "created_at": "2025-09-21T08:30:00Z",
  "processed_at": "2025-09-21T08:35:00Z",
  "raw_scraped_data": {
    "detail_url": "...",
    "captured": [/* Complete API responses */],
    "timestamp": "..."
  },
  "formatted_metadata": {
    "documentsoort": "niet-dossierstuk",
    "woo_informatiecategorie": "vergaderstukken Staten-Generaal", 
    "gepubliceerd_op": "15-09-2025, 00:00",
    "laatst_gewijzigd": "15-09-2025, 16:00",
    "bestandstype": "PDF",
    "identificatiekenmerk": "blg-1212930",
    "extra_metadata_fields": {
      "vergaderjaar": {"values": ["2024-2025"]},
      "documentsubsoort": {"values": ["Bijlage"]}
    }
    /* ... all other webpage fields ... */
  }
}
```

## 🔍 MongoDB Queries

```javascript
// Count total URLs
db.urls.countDocuments({})

// Count processed URLs  
db.urls.countDocuments({processed: true})

// Get unprocessed URLs
db.urls.find({processed: false}).limit(10)

// Find documents by type
db.urls.find({"formatted_metadata.documentsoort": "niet-dossierstuk"})

// Find PDFs
db.urls.find({"formatted_metadata.bestandstype": "PDF"})

// Get recent documents
db.urls.find({}).sort({processed_at: -1}).limit(10)
```

## 🖥️ Production Deployment

### Using Screen (Recommended)

```bash
# Start collection in background
screen -S url-collector
python mongodb_url_scraper.py collect
# Detach: Ctrl+A, then D

# Start processing in background  
screen -S url-processor
python mongodb_url_scraper.py process
# Detach: Ctrl+A, then D

# Monitor progress
screen -S monitor
python mongodb_monitor.py
```

### Resume After Interruption

The scraper automatically resumes where it left off:
```bash
# Will skip already processed URLs
python mongodb_url_scraper.py process
```

## 📈 Performance

- **Processing speed**: ~2-5 seconds per URL
- **Estimated time**: 
  - 1,000 URLs ≈ 1-2 hours
  - 10,000 URLs ≈ 10-20 hours
- **Memory usage**: ~50-100MB
- **Storage**: ~1-5KB per URL record

## 🛡️ Error Handling

- **Network errors**: Automatically retries and continues
- **MongoDB errors**: Logs errors and continues processing
- **Graceful shutdown**: Ctrl+C stops safely without data loss
- **Resume capability**: Skips already processed URLs on restart

## 📝 Logging

All activities are logged to console with timestamps:
- `[INFO]` - General information
- `[SUCCESS]` - Successful operations  
- `[ERROR]` - Error messages
- `[SCRAPING]` - Currently processing URL

## 🔧 Troubleshooting

**MongoDB connection issues:**
```bash
# Test connection
python -c "from mongodb_url_scraper import MongoDBURLScraper; s=MongoDBURLScraper(); print('OK' if s.connect_to_mongodb() else 'FAILED')"
```

**Playwright browser issues:**
```bash
playwright install chromium
```

**Check processing status:**
```bash
python mongodb_monitor.py
```

## 📄 License

This project is for educational and research purposes.
