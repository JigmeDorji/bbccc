# Technology Stack Documentation

## Overview
BBCCC is a full-featured school management system built using traditional LAMP stack architecture, providing comprehensive functionality for managing educational institutions.

---

## Backend Technologies

### Core Language
| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 7.0+ | Server-side application logic and business rules |
| MySQL | 5.6+ | Primary relational database for data persistence |

### Database Layer
- **PDO (PHP Data Objects)**: Used for all database connections with prepared statements to prevent SQL injection
- **Database Engine**: InnoDB for ACID compliance and foreign key support
- **Character Encoding**: UTF-8 (utf8mb4) for full Unicode support including emojis and special characters

### PHP Libraries & Packages

#### Email Handling
- **PHPMailer 7.0+** 
  - SMTP email sending
  - HTML email templates
  - Attachment support
  - Used for password resets and notifications

#### Document Generation
- **PHPExcel**
  - Excel file generation (.xls, .xlsx)
  - Fee statements and reports export
  - Student data bulk export

- **TCPDF**
  - PDF document generation
  - Receipt printing
  - Report generation
  - Custom fonts support

#### Dependency Management
- **Composer**
  - Manages PHP dependencies
  - Autoloading classes
  - Currently managing PHPMailer

---

## Frontend Technologies

### Core Technologies
| Technology | Purpose |
|------------|---------|
| HTML5 | Semantic markup structure |
| CSS3 | Modern styling with animations and transitions |
| JavaScript (ES5+) | Client-side logic and interactivity |

### JavaScript Frameworks & Libraries

#### Primary Framework
- **jQuery 1.12.0**
  - DOM manipulation
  - Event handling
  - AJAX requests for dynamic content
  - Cross-browser compatibility

#### UI Framework
- **Bootstrap (3.x/4.x)**
  - Responsive grid system
  - Pre-built components
  - Mobile-first design
  - Form validation and styling

### Frontend Plugins & Components

#### Sliders & Carousels
- **Owl Carousel**: Touch-enabled responsive carousel
  - Image galleries
  - Testimonials slider
  - Content carousels
  
- **Nivo Slider**: Professional image slider
  - Hero section animations
  - Banner rotations

#### Animations & Effects
- **WOW.js**: Scroll-triggered animations
- **Animate.css**: Pre-built CSS animations
- **jQuery CounterUp**: Animated number counting
- **Waypoints**: Scroll-based event triggering

#### Layout & Filtering
- **Isotope**: Dynamic grid layouts
  - Portfolio filtering
  - Content sorting
  - Masonry layouts

- **jQuery MixItUp**: Animated content filtering
  - Category filtering
  - Search functionality

#### User Interface
- **Venobox**: Responsive lightbox
  - Image popups
  - Video embedding
  - Gallery viewing

- **jQuery Mean Menu**: Responsive navigation menu
  - Mobile-friendly hamburger menu
  - Multi-level navigation

#### Visualization
- **Chart.js**: JavaScript charting
  - Fee statistics
  - Attendance graphs
  - Financial reports visualization

#### Navigation & Scrolling
- **jQuery ScrollToFixed**: Sticky navigation
- **jQuery Nav**: One-page navigation
- **Smooth Scroll**: Smooth scrolling behavior
- **ScrollUp**: Back-to-top button

### CSS Framework & Styling

#### Icons & Fonts
- **Font Awesome**: 
  - Vector icons
  - Social media icons
  - UI elements

#### CSS Preprocessor
- **SASS/SCSS**:
  - Located in `assets/scss/`
  - Variables and mixins
  - Modular stylesheets
  - Better code organization

#### Responsive Design
- Mobile-first approach
- Breakpoints for various devices
- Flexible grid systems
- Touch-friendly interfaces

---

## Development & Build Tools

### Version Control
- **Git**: Source code management and version control

### Frontend Build Tools
- **SASS Compilation**: CSS preprocessing
  - Configuration: `assets/config-scss.bat`
  - Source: `assets/scss/`
  - Output: `assets/css/`

### Browser Compatibility
- **Modernizr 2.8.3**:
  - Feature detection
  - Graceful degradation
  - Polyfills for older browsers

---

## External APIs & Services

### Third-Party Integrations
- **Google Maps API**:
  - Location mapping
  - Contact page integration
  - Interactive maps

---

## Server Environment

### Recommended Server Setup

#### Web Server Options
- Apache 2.4+ with mod_rewrite
- Nginx 1.18+
- PHP-FPM for better performance

#### PHP Configuration
```ini
PHP Version: 7.0 or higher (7.4+ recommended)
memory_limit: 128M minimum
upload_max_filesize: 10M minimum
post_max_size: 10M minimum
max_execution_time: 300
```

#### Required PHP Extensions
- `pdo_mysql`: Database connectivity
- `mysqli`: MySQL improved extension
- `gd`: Image processing
- `mbstring`: Multibyte string handling
- `openssl`: Secure communications
- `curl`: HTTP requests
- `zip`: Archive handling
- `xml`: XML parsing
- `json`: JSON handling

#### Database Server
- MySQL 5.6+ or MariaDB 10.1+
- InnoDB storage engine
- UTF-8 (utf8mb4) character set support

### File Structure
```
bbccc/
├── assets/              # Admin panel assets
│   ├── css/
│   ├── js/
│   ├── scss/
│   └── images/
├── bbccassests/         # Public website assets
│   ├── css/
│   ├── js/
│   ├── img/
│   └── venobox/
├── include/             # Shared PHP includes
│   ├── config.php       # Database configuration
│   ├── auth.php         # Authentication logic
│   ├── nav.php          # Navigation components
│   └── footer.php       # Footer components
├── uploads/             # User uploaded files
├── PHPExcel/            # Excel library
├── tcpdf/               # PDF library
├── vendor/              # Composer dependencies
└── *.php                # Application pages
```

---

## Security Features

### Implemented Security Measures
1. **SQL Injection Prevention**
   - PDO prepared statements
   - Parameterized queries

2. **Session Management**
   - PHP sessions for user authentication
   - Session timeout handling
   - Access control checks

3. **Password Security**
   - Password hashing (should verify implementation)
   - Forgot password functionality
   - Password reset via email

4. **Access Control**
   - Role-based access (Admin, Parent)
   - Authorization checks on pages
   - Unauthorized access redirects

5. **File Upload Security**
   - File type validation
   - Size restrictions
   - Secure storage in uploads directory

---

## Application Modules

### Core Modules
1. **Authentication System**
   - Login/Logout
   - Password reset
   - Session management

2. **Student Management**
   - Student registration
   - Profile management
   - Class assignment

3. **Fee Management**
   - Fee structure setup
   - Payment processing
   - Receipt generation
   - Payment history

4. **Attendance System**
   - Daily attendance tracking
   - Parent view
   - Reports generation

5. **Accounting Module**
   - Account head management
   - Journal entries
   - Financial statements
   - Sub-account management

6. **User Management**
   - Admin users
   - Parent accounts
   - Role assignment
   - Profile management

7. **Company/Institution Setup**
   - Multi-institution support
   - Company profile
   - Settings configuration

8. **Content Management**
   - About us page
   - Services
   - Team management
   - Banner management
   - Project portfolio

9. **Communication**
   - Email notifications
   - Feedback system
   - Contact forms

---

## Browser Support

### Supported Browsers
- Chrome (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)
- Edge (latest 2 versions)
- Internet Explorer 11 (limited support)

### Mobile Support
- iOS Safari 10+
- Android Chrome 60+
- Responsive design for all screen sizes

---

## Performance Optimizations

### Implemented Optimizations
1. **Frontend**
   - Minified CSS and JavaScript
   - Image optimization
   - Lazy loading for images
   - Browser caching

2. **Database**
   - Indexed columns for faster queries
   - Optimized table structures
   - Connection pooling via PDO

3. **Caching**
   - Browser caching via headers
   - Static asset caching

---

## Deployment Requirements

### Minimum Server Requirements
- **OS**: Linux (Ubuntu 18.04+, CentOS 7+) or Windows Server
- **RAM**: 512MB minimum, 2GB recommended
- **Storage**: 1GB minimum for application
- **Bandwidth**: Based on concurrent users

### Recommended Hosting Environment
- Shared hosting with PHP/MySQL support
- VPS with LAMP stack
- Cloud hosting (AWS, DigitalOcean, etc.)
- Control panel: cPanel, Plesk, or DirectAdmin

### Local Development
- **WAMP** (Windows)
- **XAMPP** (Cross-platform)
- **MAMP** (macOS)
- **LAMP** (Linux)

---

## Future Technology Considerations

### Potential Upgrades
1. **Backend**
   - Upgrade to PHP 8.x for better performance
   - Implement a proper MVC framework (Laravel, CodeIgniter)
   - RESTful API development

2. **Frontend**
   - Modern JavaScript framework (Vue.js, React)
   - jQuery to Vanilla JS migration
   - Progressive Web App (PWA) capabilities

3. **Database**
   - Query optimization and indexing
   - Database replication for scalability
   - Caching layer (Redis, Memcached)

4. **Security**
   - Two-factor authentication
   - Enhanced password policies
   - Security headers implementation
   - HTTPS enforcement

5. **DevOps**
   - Docker containerization
   - CI/CD pipeline
   - Automated testing
   - Monitoring and logging

---

## License & Third-Party Licenses

Please ensure compliance with licenses for:
- Bootstrap: MIT License
- jQuery: MIT License
- Font Awesome: Font Awesome Free License
- PHPMailer: LGPL 2.1
- TCPDF: LGPL 3.0
- PHPExcel: LGPL 2.1
- Other jQuery plugins: Various (check individual licenses)

---

## Support & Maintenance

### Version Information
- Current Version: Check application for version number
- Database Schema: See `dbScript.sql`
- Last Updated: Check git history

### Documentation
- Deployment Guide: See `Read me` file
- Database Schema: `dbScript.sql`
- Configuration: `include/config.php`

---

**Document Last Updated**: 2026-02-07
