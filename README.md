# BBCC Website

A PHP-based school management system.

## Quick Start

To run this website using PHP's built-in development server:

```bash
php -S localhost:8000
```

Then open your browser and navigate to: `http://localhost:8000`

You can also specify a different port:
```bash
php -S localhost:3000
```

## Setup Instructions

### Prerequisites
- PHP 7.4 or higher
- MySQL database server

### Installation Steps

1. **Configure Database Connection**
   - Open `include/config.php`
   - Update the database credentials:
     ```php
     $DB_HOST = "localhost";
     $DB_USER = "root";
     $DB_PASSWORD = ""; // enter your db password
     $DB_NAME = "bbcc_db";
     ```

2. **Import Database**
   - Import the database script `dbScript.sql` into your MySQL server
   - You can use phpMyAdmin, MySQL Workbench, or command line:
     ```bash
     mysql -u root -p bbcc_db < dbScript.sql
     ```

3. **Run the Website**
   
   **Option 1: Using the start script (Linux/Mac)**
   ```bash
   ./start.sh        # Starts on port 8000
   ./start.sh 3000   # Starts on port 3000
   ```
   
   **Option 2: Direct PHP command**
   ```bash
   php -S localhost:8000
   ```
   
   - Open your browser to `http://localhost:8000`

## Admin Login

Default admin credentials:
- **Username:** admin
- **Password:** 1234

## Alternative Setup (WampServer)

If you prefer using WampServer:
1. Copy the entire project folder to your WampServer's `www` directory
2. Configure database settings in `include/config.php`
3. Import `dbScript.sql` into your database
4. Access via `http://localhost/bbccc` in your browser
