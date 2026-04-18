# Kindness Coin – Project Guidelines

## Project Overview
A Lions Club community website called **Kindness Coin**. Static HTML front-end with a PHP back-end for form processing and story submissions.

## Stack
- **Front-end**: HTML5, Bootstrap 5, vanilla JavaScript (ES6, strict mode IIFE)
- **CSS**: Bootstrap 5 + custom `assets/css/main.css`
- **Vendor libraries**: AOS (scroll animations), GLightbox, Swiper, Isotope, PureCounter, Leaflet (maps)
- **Icons**: Bootstrap Icons (`bi-*` classes)
- **Back-end**: PHP 8+, Composer, PHPMailer for transactional email
- **Form data storage**: CSV at `forms/data/stories.csv`

## Conventions

### HTML
- Every page shares the same header/nav and footer structure — keep them consistent across `index.html`, `about.html`, `faq.html`, `vision.html`, `inspiration.html`, `kindness-coin.html`.
- Use Bootstrap grid classes for layout; do not add inline styles.
- Data attributes like `data-aos` drive scroll animations — preserve them.

### JavaScript
- All custom JS lives in `assets/js/main.js` (site-wide) and `assets/js/custom-animations.js`.
- Follow the existing strict-mode IIFE pattern — no global variables.
- Prefer `document.querySelector` / `querySelectorAll` over jQuery.

### PHP / Back-end
- Use Composer autoloader (`vendor/autoload.php`) for PHPMailer.
- Load SMTP credentials and config from `forms/config.php` — never hard-code credentials.
- Always validate and sanitize `$_POST` input before use; output JSON responses with correct HTTP status codes.
- Rate-limit form submissions via PHP sessions (pattern already in `submit-story.php`).

### Security
- Escape all user-supplied values before writing to CSV or sending in email bodies.
- Do not expose server paths or stack traces in JSON error responses.

## Build & Deployment
No build step — files are served directly. Composer dependencies are committed (`vendor/`).
