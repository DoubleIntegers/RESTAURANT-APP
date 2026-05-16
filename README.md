# Restaurant Explorer — Setup Guide

## Requirements
- PHP 7.4+ with `mongodb` extension (`ext-mongodb`)
- MongoDB running on localhost:27017
- `restaurants.json` in this folder

## File Structure
```
restaurant-app/
├── config.php       ← MongoDB connection settings
├── import.php       ← Run once to load data
├── api.php          ← JSON API (AJAX backend)
├── index.php        ← UI (open this in browser)
└── restaurants.json ← Your data file (copy here)
```

## Quick Start

### 1. Copy restaurants.json
```bash
cp /path/to/restaurants.json ./restaurant-app/
```

### 2. Edit config.php (if needed)
```php
define('MONGO_URI',        'mongodb://localhost:27017');
define('MONGO_DB',         'restaurantdb');
define('MONGO_COLLECTION', 'restaurants');
```

### 3. Import data (run once)
```bash
php import.php
```
Expected output:
```
Collection dropped (fresh import).
Inserted 200 records...
...
✅ Done! Imported 3772 restaurants into MongoDB.
```

### 4. Serve with PHP
```bash
cd restaurant-app
php -S localhost:8080
```
Then open: http://localhost:8080

---

## Features
- **Borough filter** — dropdown populated from DB
- **Cuisine keyword search** — case-insensitive regex
- **Last grade score ≤ N** — slider or number input
- **Table / Card view** toggle
- **Detail modal** — click any row/card
- **Pagination** — 20 per page
- **Live query display** — shows MongoDB query syntax

## Notes
- `grades[0]` is treated as the **most recent** inspection (as-is in dataset)
- Score coloring: green ≤13 (A-range), yellow ≤27 (B-range), red >27 (C+)
