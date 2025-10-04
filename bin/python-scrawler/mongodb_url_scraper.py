# mongodb_url_scraper.py
"""
MongoDB URL Scraper - Stores URLs and updates records with scraped data
Goal: Store scraped data directly in MongoDB records, not JSON files
"""

import asyncio
import json
from datetime import datetime, timezone
from urllib.parse import urljoin

from playwright.async_api import async_playwright, TimeoutError as PWTimeout
from pymongo import MongoClient, UpdateOne
from pymongo.errors import ConnectionFailure

# --- Constants ---
BASE_URL = "https://open.overheid.nl"
SEARCH_URL_TEMPLATE = BASE_URL + "/zoeken?zoeken=&pagina={page}"
MONGO_CONNECTION_STRING = "mongodb://root:14396Oem0012443@212.132.107.72:27017/"
DB_NAME = "open_overheid"
COLLECTION_NAME = "urls"

class MongoDBURLScraper:
    def __init__(self):
        self.client = None
        self.db = None
        self.collection = None
    
    def connect_to_mongodb(self):
        """Establish connection to MongoDB"""
        try:
            self.client = MongoClient(MONGO_CONNECTION_STRING, serverSelectionTimeoutMS=5000)
            self.client.admin.command('ismaster')
            print("[SUCCESS] MongoDB connection successful.")
            self.db = self.client[DB_NAME]
            self.collection = self.db[COLLECTION_NAME]
            return True
        except ConnectionFailure as e:
            print(f"[ERROR] MongoDB connection failed: {e}")
            return False
    
    def store_urls_in_mongodb(self, urls):
        """Store URLs in MongoDB with initial structure"""
        if not urls:
            return 0

        operations = []
        for url in urls:
            operations.append(
                UpdateOne(
                    {"url": url},
                    {
                        "$setOnInsert": {
                            "url": url,
                            "processed": False,
                            "created_at": datetime.now(timezone.utc),
                            "raw_scraped_data": None,
                            "formatted_metadata": None
                        }
                    },
                    upsert=True
                )
            )
        
        result = self.collection.bulk_write(operations)
        return result.upserted_count
    
    def get_unprocessed_urls(self, limit=None):
        """Get unprocessed URLs from MongoDB"""
        try:
            query = {"processed": False}
            cursor = self.collection.find(query)
            if limit:
                cursor = cursor.limit(limit)
            
            urls = list(cursor)
            print(f"[INFO] Retrieved {len(urls)} unprocessed URLs from MongoDB")
            return urls
        except Exception as e:
            print(f"[ERROR] Error retrieving URLs from MongoDB: {e}")
            return []
    
    def update_url_with_scraped_data(self, url, raw_data, formatted_data):
        """Update MongoDB record with scraped data"""
        try:
            self.collection.update_one(
                {"url": url},
                {
                    "$set": {
                        "processed": True,
                        "processed_at": datetime.now(timezone.utc),
                        "raw_scraped_data": raw_data,
                        "formatted_metadata": formatted_data
                    }
                }
            )
            print(f"  [SUCCESS] Updated MongoDB record for {url}")
        except Exception as e:
            print(f"  [ERROR] Error updating MongoDB record: {e}")

    def format_date_for_display(self, date_string):
        """Format date string to match webpage display format"""
        if not date_string:
            return ''
        
        try:
            # Handle ISO format dates with microseconds
            if 'T' in date_string:
                if '.' in date_string:
                    date_string = date_string.split('.')[0] + 'Z'
                dt = datetime.fromisoformat(date_string.replace('Z', '+00:00'))
                return dt.strftime('%d-%m-%Y, %H:%M')
            else:
                dt = datetime.strptime(date_string, '%Y-%m-%d')
                return dt.strftime('%d-%m-%Y, %H:%M')
        except:
            return date_string

    def extract_formatted_metadata(self, scraped_data):
        """Extract and format metadata for easy access"""
        if not scraped_data.get('captured'):
            return {}
        
        # Get the main API response data
        api_response = None
        for capture in scraped_data['captured']:
            if 'data' in capture:
                api_response = capture['data']
                break
        
        if not api_response or 'document' not in api_response:
            return {}
        
        api_data = api_response['document']
        metadata = {}
        
        # Basic document information
        metadata['detail_url'] = scraped_data.get('detail_url', '')
        metadata['timestamp'] = scraped_data.get('timestamp', '')
        metadata['pid'] = api_data.get('pid', '')
        metadata['weblocatie'] = api_data.get('weblocatie', '')
        metadata['identifiers'] = api_data.get('identifiers', [])
        metadata['identificatiekenmerk'] = api_data.get('identifiers', [''])[0] if api_data.get('identifiers') else ''
        
        # Dates
        metadata['creatiedatum'] = api_data.get('creatiedatum', '')
        geldigheid_begin = api_data.get('geldigheid', {}).get('begindatum', '')
        metadata['geldigheid_begindatum'] = geldigheid_begin
        metadata['geldig_van'] = self.format_date_for_display(geldigheid_begin)
        
        # Organizations
        verantwoordelijke = api_data.get('verantwoordelijke', {})
        metadata['verantwoordelijke_label'] = verantwoordelijke.get('label', '')
        metadata['verantwoordelijke_bronwaarde'] = verantwoordelijke.get('bronwaarde', '')
        
        opsteller = api_data.get('opsteller', {})
        metadata['opsteller_label'] = opsteller.get('label', '')
        metadata['opsteller_bronwaarde'] = opsteller.get('bronwaarde', '')
        
        publisher = api_data.get('publisher', {})
        metadata['publicerende_organisatie'] = publisher.get('label', '')
        
        # Language and title
        language = api_data.get('language', {})
        metadata['taal'] = language.get('label', '')
        
        titelcollectie = api_data.get('titelcollectie', {})
        metadata['officiele_titel'] = titelcollectie.get('officieleTitel', '')
        
        # Classifications
        classificatie = api_data.get('classificatiecollectie', {})
        
        documentsoorten = classificatie.get('documentsoorten', [])
        if documentsoorten:
            metadata['documentsoort'] = documentsoorten[0].get('label', '')
        
        themas = classificatie.get('themas', [])
        metadata['themas'] = ', '.join([theme.get('label', '') for theme in themas])
        
        informatiecategorieen = classificatie.get('informatiecategorieen', [])
        metadata['woo_informatiecategorie'] = ', '.join([cat.get('label', '') for cat in informatiecategorieen])
        metadata['informatiecategorieen'] = [cat.get('label', '') for cat in informatiecategorieen]
        
        # Extra metadata with value sets
        extra_metadata = api_data.get('extraMetadata', [])
        metadata['extra_metadata_fields'] = {}
        
        for extra in extra_metadata:
            if extra.get('prefix') == 'plooi.displayfield':
                velden = extra.get('velden', [])
                for veld in velden:
                    key = veld.get('key', '')
                    values = veld.get('values', [])
                    metadata['extra_metadata_fields'][key] = {
                        'values': values,
                        'first_value': values[0] if values else '',
                        'all_values_string': ', '.join(values) if values else ''
                    }
                    
                    if key == 'vergaderjaar':
                        metadata['vergaderjaar'] = values[0] if values else ''
                        metadata['vergaderjaar_values'] = values
                    elif key == 'documentsubsoort':
                        metadata['documentsubsoort'] = values[0] if values else ''
                        metadata['documentsubsoort_values'] = values
                    else:
                        metadata[key] = values[0] if values else ''
                        metadata[f'{key}_values'] = values
        
        # File information
        versies = api_response.get('versies', [])
        if versies:
            bestanden = versies[0].get('bestanden', [])
            if bestanden:
                bestand = bestanden[0]
                mime_type = bestand.get('mime-type', '')
                if mime_type == 'application/pdf':
                    metadata['bestandstype'] = 'PDF'
                else:
                    metadata['bestandstype'] = mime_type.split('/')[-1].upper() if mime_type else ''
                
                metadata['bestandsnaam'] = bestand.get('bestandsnaam', '')
                metadata['bestandsgrootte'] = bestand.get('grootte', 0)
                metadata['paginas'] = bestand.get('paginas', 0)
                metadata['download_url'] = bestand.get('url', '')
                metadata['hash'] = bestand.get('hash', '')
            
            metadata['gepubliceerd_op'] = self.format_date_for_display(versies[0].get('openbaarmakingsdatum', ''))
            metadata['laatst_gewijzigd'] = self.format_date_for_display(versies[0].get('mutatiedatumtijd', ''))
            metadata['openbaarmakingsdatum'] = versies[0].get('openbaarmakingsdatum', '')
            metadata['mutatiedatumtijd'] = versies[0].get('mutatiedatumtijd', '')
        
        # Internal PLOOI information
        plooi_intern = api_response.get('plooiIntern', {})
        metadata['aanbieder'] = plooi_intern.get('aanbieder', '')
        metadata['source_label'] = plooi_intern.get('sourceLabel', '')
        metadata['publicatiestatus'] = plooi_intern.get('publicatiestatus', '')
        metadata['raw_extra_metadata'] = api_data.get('extraMetadata', [])
        
        return metadata

    async def scrape_url_details(self, page, detail_url):
        """Scrape detailed information from a single URL"""
        print(f"[SCRAPING] {detail_url}")
        captured_json = []
        
        async def on_response(response):
            try:
                content_type = response.headers.get("content-type", "")
                if "application/json" in content_type:
                    text = await response.text()
                    if text and len(text) > 10:
                        captured_json.append({
                            "url": response.url,
                            "status": response.status,
                            "json_text": text
                        })
            except Exception as e:
                print(f"  [WARNING] Error capturing response: {e}")
        
        page.on("response", on_response)
        
        try:
            await page.goto(detail_url, wait_until="networkidle", timeout=60000)
            await asyncio.sleep(2.0)
        except Exception as e:
            print(f"  [ERROR] Error navigating to {detail_url}: {e}")
            page.remove_listener("response", on_response)
            return {"detail_url": detail_url, "error": str(e), "captured": []}
        
        # Process captured JSON responses
        result = {"detail_url": detail_url, "captured": [], "timestamp": datetime.now(timezone.utc).isoformat()}
        
        if captured_json:
            print(f"  [SUCCESS] Captured {len(captured_json)} JSON responses")
            for item in captured_json:
                try:
                    parsed_data = json.loads(item["json_text"])
                    result["captured"].append({
                        "response_url": item["url"],
                        "status": item["status"],
                        "data": parsed_data
                    })
                except json.JSONDecodeError:
                    result["captured"].append({
                        "response_url": item["url"],
                        "status": item["status"],
                        "raw_text": item["json_text"]
                    })
        else:
            print("  [INFO] No JSON responses found")
        
        page.remove_listener("response", on_response)
        return result

    async def collect_search_links(self, page, page_num):
        """Collect URLs from search result pages"""
        url = SEARCH_URL_TEMPLATE.format(page=page_num)
        print(f"[INFO] Collecting URLs from page {page_num}: {url}")
        
        try:
            await page.goto(url, wait_until="networkidle", timeout=60000)
            await page.wait_for_selector("a[href*='/details/']", timeout=10000)
        except PWTimeout:
            print(f"[INFO] No detail links found on page {page_num}")
            return []

        anchors = await page.query_selector_all("a[href*='/details/']")
        links = set()
        for a in anchors:
            href = await a.get_attribute("href")
            if href:
                full_url = urljoin(BASE_URL, href)
                if "/details/" in full_url:
                    links.add(full_url)
        
        return list(links)

    async def collect_urls_from_search(self, max_pages=None):
        """Collect URLs from search pages and store in MongoDB"""
        if not self.connect_to_mongodb():
            return
        
        async with async_playwright() as pw:
            browser = await pw.chromium.launch(headless=True)
            context = await browser.new_context(
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
            )
            page = await context.new_page()
            
            page_num = 1
            total_new_urls = 0
            
            while True:
                if max_pages and page_num > max_pages:
                    break
                    
                links = await self.collect_search_links(page, page_num)
                if not links:
                    print("[INFO] No more links found. Stopping pagination.")
                    break
                
                new_urls_count = self.store_urls_in_mongodb(links)
                total_new_urls += new_urls_count
                print(f"[INFO] Page {page_num}: Found {len(links)} links, stored {new_urls_count} new URLs")
                
                page_num += 1
                await asyncio.sleep(1)  # Be respectful to the server
            
            print(f"[COMPLETE] URL collection finished. Total new URLs: {total_new_urls}")
            await browser.close()

    async def process_unprocessed_urls(self, limit=None):
        """Process all unprocessed URLs and update MongoDB records"""
        if not self.connect_to_mongodb():
            return
        
        urls = self.get_unprocessed_urls(limit=limit)
        if not urls:
            print("[INFO] No unprocessed URLs found.")
            return
        
        print(f"[INFO] Starting to process {len(urls)} URLs...")
        
        async with async_playwright() as pw:
            browser = await pw.chromium.launch(headless=True)
            context = await browser.new_context(
                user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
            )
            page = await context.new_page()
            
            processed_count = 0
            for url_doc in urls:
                url = url_doc["url"]
                try:
                    # Scrape the URL
                    raw_data = await self.scrape_url_details(page, url)
                    formatted_data = self.extract_formatted_metadata(raw_data)
                    
                    # Update MongoDB record directly
                    self.update_url_with_scraped_data(url, raw_data, formatted_data)
                    processed_count += 1
                    
                    print(f"  [SUCCESS] Processed {url} ({processed_count}/{len(urls)})")
                    await asyncio.sleep(1)  # Be respectful to the server
                    
                except Exception as e:
                    print(f"  [ERROR] Error processing {url}: {e}")
                    # Still mark as processed to avoid reprocessing
                    self.update_url_with_scraped_data(url, {"error": str(e)}, {})
            
            await browser.close()
        
        print(f"[COMPLETE] Processing finished. Successfully processed {processed_count} URLs.")
    
    def close_connection(self):
        """Close MongoDB connection"""
        if self.client:
            self.client.close()
            print("[INFO] MongoDB connection closed.")

# Main functions for different operations
async def collect_urls():
    """Collect URLs from search pages"""
    scraper = MongoDBURLScraper()
    try:
        await scraper.collect_urls_from_search()
    finally:
        scraper.close_connection()

async def process_urls(limit=None):
    """Process unprocessed URLs"""
    scraper = MongoDBURLScraper()
    try:
        await scraper.process_unprocessed_urls(limit=limit)
    finally:
        scraper.close_connection()

if __name__ == "__main__":
    import sys
    
    if len(sys.argv) > 1:
        if sys.argv[1] == "collect":
            print("Starting URL collection...")
            asyncio.run(collect_urls())
        elif sys.argv[1] == "process":
            limit = int(sys.argv[2]) if len(sys.argv) > 2 else None
            print(f"Starting URL processing (limit: {limit})...")
            asyncio.run(process_urls(limit))
        else:
            print("Usage: python mongodb_url_scraper.py [collect|process] [limit]")
    else:
        print("MongoDB URL Scraper")
        print("Usage:")
        print("  python mongodb_url_scraper.py collect     # Collect URLs from search")
        print("  python mongodb_url_scraper.py process     # Process all unprocessed URLs")
        print("  python mongodb_url_scraper.py process 10  # Process 10 URLs (for testing)")
