# mongodb_monitor.py
"""
Simple MongoDB progress monitor for URL scraping
"""

import os
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from pymongo import MongoClient
from pymongo.errors import ConnectionFailure
from dotenv import load_dotenv

# Load environment variables from Laravel .env file
env_path = Path(__file__).parent.parent.parent / '.env'
if env_path.exists():
    load_dotenv(env_path)
    print(f"[INFO] Loaded .env from: {env_path}")
else:
    print(f"[WARNING] .env file not found at: {env_path}")
    # Try alternative path
    alt_env_path = Path(__file__).parent.parent.parent.absolute() / '.env'
    if alt_env_path.exists():
        load_dotenv(alt_env_path)
        print(f"[INFO] Loaded .env from: {alt_env_path}")
    else:
        print(f"[ERROR] .env file not found at: {alt_env_path}")

# Build MongoDB connection string from .env variables
DB_HOST = os.getenv('DB_HOST', '127.0.0.1')
DB_PORT = os.getenv('DB_PORT', '27017')
DB_DATABASE = os.getenv('DB_DATABASE', 'scrawler')
DB_USERNAME = os.getenv('DB_USERNAME', '')
DB_PASSWORD = os.getenv('DB_PASSWORD', '')

# Construct MongoDB connection string
if DB_USERNAME and DB_PASSWORD:
    MONGO_CONNECTION_STRING = f"mongodb://{DB_USERNAME}:{DB_PASSWORD}@{DB_HOST}:{DB_PORT}/"
elif DB_USERNAME:
    MONGO_CONNECTION_STRING = f"mongodb://{DB_USERNAME}@{DB_HOST}:{DB_PORT}/"
else:
    MONGO_CONNECTION_STRING = f"mongodb://{DB_HOST}:{DB_PORT}/"

DB_NAME = DB_DATABASE
COLLECTION_NAME = "urls"

print(f"[INFO] Using MongoDB connection: {MONGO_CONNECTION_STRING.replace(DB_PASSWORD, '***' if DB_PASSWORD else '')}")

def connect_to_mongodb():
    """Connect to MongoDB"""
    try:
        client = MongoClient(MONGO_CONNECTION_STRING, serverSelectionTimeoutMS=5000)
        client.admin.command('ismaster')
        db = client[DB_NAME]
        collection = db[COLLECTION_NAME]
        return client, collection
    except ConnectionFailure as e:
        print(f"MongoDB connection failed: {e}")
        return None, None

def get_stats(collection):
    """Get current statistics"""
    try:
        total = collection.count_documents({})
        processed = collection.count_documents({"processed": True})
        unprocessed = collection.count_documents({"processed": False})

        # Get recently processed (last 10 minutes)
        ten_min_ago = datetime.now(timezone.utc) - timedelta(minutes=10)
        recent = collection.count_documents({
            "processed": True,
            "processed_at": {"$gte": ten_min_ago}
        })

        return {
            "total": total,
            "processed": processed,
            "unprocessed": unprocessed,
            "recent": recent,
            "progress": (processed / total * 100) if total > 0 else 0
        }
    except Exception as e:
        print(f"Error getting stats: {e}")
        return None

def monitor():
    """Monitor progress"""
    print("MongoDB URL Scraper - Progress Monitor")
    print("=" * 50)

    client, collection = connect_to_mongodb()
    if collection is None:
        return

    try:
        while True:
            stats = get_stats(collection)
            if stats:
                now = datetime.now().strftime("%H:%M:%S")
                print(f"[{now}] Total: {stats['total']:,} | "
                      f"Processed: {stats['processed']:,} | "
                      f"Remaining: {stats['unprocessed']:,} | "
                      f"Progress: {stats['progress']:.1f}% | "
                      f"Recent: {stats['recent']}")

                if stats['unprocessed'] == 0:
                    print("\n🎉 All URLs processed!")
                    break

            time.sleep(30)  # Update every 30 seconds

    except KeyboardInterrupt:
        print("\nMonitoring stopped.")
    finally:
        client.close()

if __name__ == "__main__":
    monitor()
