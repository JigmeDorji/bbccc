# BBCCC - School Management System

A comprehensive web-based school management system built with PHP and MySQL, designed to manage students, fees, attendance, accounting, and more.

## Tech Stack

### Backend
- **PHP** - Server-side scripting language (Core application logic)
- **MySQL** - Relational database management system
- **PDO (PHP Data Objects)** - Database abstraction layer for secure database connections

### Frontend
- **HTML5** - Markup structure
- **CSS3** - Styling with responsive design
- **JavaScript (ES5+)** - Client-side interactivity
- **jQuery 1.12.0** - JavaScript library for DOM manipulation and AJAX
- **Bootstrap 3/4** - Responsive CSS framework

### UI/UX Libraries & Plugins
- **Font Awesome** - Icon library
- **Owl Carousel** - Touch-enabled jQuery slider
- **Nivo Slider** - Image slider plugin
- **WOW.js** - Scroll reveal animations
- **Animate.css** - CSS animation library
- **Isotope** - Grid layout and filtering
- **Venobox** - Responsive lightbox plugin
- **jQuery MixItUp** - Animated filtering and sorting
- **jQuery CounterUp** - Number counting animations
- **Waypoints** - Trigger functions on scroll
- **Chart.js** - JavaScript charting library

### PHP Libraries & Dependencies
- **PHPMailer 7.0+** - Email sending functionality
- **PHPExcel** - Excel file generation and manipulation
- **TCPDF** - PDF generation library
- **Composer** - PHP dependency management

### Development Tools
- **Git** - Version control
- **SASS/SCSS** - CSS preprocessor (assets/scss)
- **Modernizr 2.8.3** - Feature detection library

### Server Requirements
- **Web Server**: Apache/Nginx with PHP support
- **PHP Version**: 7.0+ recommended
- **MySQL Version**: 5.6+ or MariaDB equivalent
- **Required PHP Extensions**:
  - PDO MySQL
  - GD Library (for image processing)
  - mbstring
  - OpenSSL (for PHPMailer)

### Key Features Implemented
- Student Management System
- Fee Management & Payment Processing
- Attendance Management
- Accounting System (Journal Entries, Account Heads)
- Parent Portal
- User Authentication & Authorization
- Company/Institution Management
- Project Management
- PDF Report Generation
- Excel Export Functionality
- Email Notifications
- Responsive Admin Dashboard

### Database
- **Database Engine**: MySQL/InnoDB
- **Character Set**: utf8mb4 (Unicode support)
- **Collation**: utf8mb4_unicode_ci / utf8mb4_0900_ai_ci

### Architecture
- **Design Pattern**: Traditional MVC-like structure with separate includes
- **Session Management**: PHP Sessions for authentication
- **Security**: PDO prepared statements for SQL injection prevention
- **File Structure**: Modular PHP files with shared includes (header, footer, navigation)

### External Services
- **Google Maps API** - Location mapping functionality

---

## Installation

Please refer to the `Read me` file in the root directory for detailed deployment instructions.

## Environment Configuration

This project now supports environment-based configuration for database and mail credentials.

Set these variables before starting the app:

- `DB_HOST` (default: `127.0.0.1`)
- `DB_USER` (default: `root`)
- `DB_PASSWORD` (default: empty)
- `DB_NAME` (default: `bbcc_db`)
- `BASE_URL` (example: `http://localhost:8080`)
- `APP_DEBUG` (`1` for debug mode, otherwise `0`)

Mail settings:

- `MAIL_HOST` (default: `smtp.gmail.com`)
- `MAIL_PORT` (default: `587`)
- `MAIL_ENCRYPTION` (default: `tls`)
- `MAIL_USERNAME`
- `MAIL_PASSWORD`
- `MAIL_FROM_EMAIL`
- `MAIL_FROM_NAME` (default: `Bhutanese Centre Canberra`)
- `MAIL_DEBUG` (`1` to enable SMTP debug)
- `MAIL_LOG_FILE` (optional log file path)
