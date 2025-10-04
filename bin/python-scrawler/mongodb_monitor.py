# mongodb_monitor.py
"""
Simple MongoDB progress monitor for URL scraping
"""

import time
from datetime import datetime
from pymongo import MongoClient
from pymongo.errors import ConnectionFailure

MONGO_CONNECTION_STRING = "mongodb://root:14396Oem0012443@212.132.107.72:27017/"
DB_NAME = "open_overheid"
COLLECTION_NAME = "urls"

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
        ten_min_ago = datetime.utcnow()
        ten_min_ago = ten_min_ago.replace(minute=ten_min_ago.minute - 10)
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
