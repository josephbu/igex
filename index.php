<?php
/**
 * Image Gallery EXpress (IGEX)
 * https://github.com/josephbu/igex
 * 
 * A web-based photo gallery that organizes images by year/month structure.
 * Supports password protection, EXIF metadata display, and automatic
 * thumbnail generation. Expects photos to be organized in:
 * PHOTO_ROOT/YYYY/MM/[originals|previews|thumbs|meta]/
 * 
 * @author JB
 * @version 0.2
 */

require_once 'config.php';

session_start();

// ========== AUTHENTICATION ==========

/**
 * Handle password protection if GALLERY_PASSWORD is set in config
 * Shows login form and validates password via POST request
 */
if (defined('GALLERY_PASSWORD') && GALLERY_PASSWORD !== '') {
    // If not logged in, check POST or show form
    if (empty($_SESSION['gallery_authenticated'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_password'])) {
            if ($_POST['gallery_password'] === GALLERY_PASSWORD) {
                $_SESSION['gallery_authenticated'] = true;
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $error = "Incorrect password.";
            }
        }
        // Show login form and exit
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Gallery Login</title>
            <style>
                body { background: #121212; color: #e0e0e0; font-family: sans-serif; }
                .login-box { max-width: 350px; margin: 10vh auto; padding: 2em; background: #1e1e1e; border-radius: 8px; box-shadow: 0 2px 8px #000a; }
                input[type=password] { width: 100%; padding: 0.5em; margin-top: 1em; margin-bottom: 1em; border-radius: 5px; border: 1px solid #444; background: #222; color: #eee; }
                input[type=submit] { width: 100%; padding: 0.5em; border-radius: 5px; border: none; background: #64b5f6; color: #222; font-weight: bold; }
                .error { color: #ff8888; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>Gallery Login</h2>
                <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <form method="post">
                    <input type="password" name="gallery_password" placeholder="Password" required>
                    <input type="submit" value="Login">
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

/**
 * Handle logout requests - destroys session and redirects to clean URL
 */
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ========== PATH HELPERS ==========

/**
 * Get the configured image extension (webp or jpg)
 * @return string File extension without a dot
 */
function get_image_format_extension() {
    $format = defined('IMAGE_FORMAT') ? strtolower(IMAGE_FORMAT) : 'jpg';
    return ($format === 'webp') ? 'webp' : 'jpg';
}

/**
 * Get the base web path for the gallery application
 * Handles cases where gallery is in subdirectory vs web root
 * @return string Base path without trailing slash
 */
function get_gallery_base_path() {
    $script_name = $_SERVER['SCRIPT_NAME'];
    return rtrim(dirname($script_name), '/\\');
}

/**
 * Get base path with trailing slash for URL construction
 * @return string Base path with trailing slash
 */
function get_base_path() {
    $base = get_gallery_base_path();
    return $base === '' ? '/' : $base . '/';
}

/**
 * Calculate the web-accessible path to the photo root directory
 * Handles complex path resolution between filesystem and web paths
 * Uses static caching to avoid recalculation on multiple calls
 * @return string Web path to photo root (without trailing slash)
 */
function photo_root_web_path() {
    static $web_path = null;
    if ($web_path !== null) return $web_path;
    // Get filesystem paths
    $script_path = $_SERVER['SCRIPT_FILENAME'];
    $web_root = dirname($script_path);
    $photo_root = realpath(PHOTO_ROOT);
    // Get web-accessible base path
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    // Calculate relative path between photo_root and web_root
    if (strpos($photo_root, $web_root) === 0) {
        $rel_path = substr($photo_root, strlen($web_root));
        $rel_path = str_replace('\\', '/', ltrim($rel_path, '/\\'));
        
        $web_path = $base_path . '/' . $rel_path;
        if ($web_path === '//') $web_path = '/'; // Root case
    } else {
        // Fallback if paths don't align
        $web_path = $base_path . '/photos';
    }
    return rtrim($web_path, '/');
}

// ========== URL PARSING ==========

/**
 * Parse the incoming request URL to extract year, month, and image
 * Handles gallery navigation structure: /year/month/image
 * Validates that year is a 4-digit number
 * @return array [year|null, month_name|null, image|null]
 */
function parse_request() {
    $base = get_base_path();
    $uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    
    // Remove base path if present
    if (strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    
    // Remove leading/trailing slashes and split
    $uri = trim($uri, '/');
    
    // If URI is empty (root gallery), return nulls
    if ($uri === '') {
        return [null, null, null];
    }
    
    $parts = array_values(array_filter(explode('/', $uri)));
    
    $year = $parts[0] ?? null;
    $month_name = $parts[1] ?? null;
    $image = $parts[2] ?? null;
    
    // Validate year is actually a 4-digit year
    if ($year && !preg_match('/^\d{4}$/', $year)) {
        return [null, null, null];
    }
    
    return [$year, $month_name, $image];
}

/**
 * Convert month name to zero-padded month number
 * @param string $month_name Full month name (e.g., "January")
 * @return string|null Zero-padded month number (e.g., "01") or null if invalid
 */
function month_name_to_num($month_name) {
    $timestamp = strtotime($month_name . " 1 2000");
    return $timestamp ? date('m', $timestamp) : null;
}

/**
 * Convert month number to full month name
 * @param string $num Zero-padded month number (e.g., "01")
 * @return string Full month name (e.g., "January")
 */
function month_num_to_name($num) {
    return date('F', mktime(0,0,0,$num,1));
}

// ========== MAIN REQUEST HANDLING ==========

// Parse the URL and determine what to display
list($year, $month_name, $image) = parse_request();

// Route to appropriate view based on URL structure
if ($image) {
    echo render_image_view($year, $month_name, $image);
} else {
    echo render_gallery($year, $month_name);
}

// ========== CSS ==========

/**
 * Generate CSS link tag for current theme
 * Reads GALLERY_THEME from config, defaults to 'dark'
 * @return string HTML link tag for stylesheet
 */
function gallery_styles() {
    $theme = defined('GALLERY_THEME') ? GALLERY_THEME : 'dark';
    $css_file = $theme === 'light' ? 'light.css' : 'dark.css';
        // Get the base directory path
    $base_path = dirname($_SERVER['SCRIPT_NAME']);
    // Ensure trailing slash and proper formatting
    $base_path = rtrim($base_path, '/') . '/';
    return <<<HTML
    <link rel="stylesheet" href="{$base_path}css/$css_file">
HTML;
}

// ========== RANDOM THUMBNAIL FUNCTIONS ==========

/**
 * Get a random thumbnail from a specific month
 * @param string $year 4-digit year
 * @param string $month_num Zero-padded month number (01-12)
 * @return string|null Web path to random thumbnail or null if none found
 */
function get_random_thumbnail($year, $month_num) {
    $thumb_dir = PHOTO_ROOT . "/$year/$month_num/thumbs";
    if (!file_exists($thumb_dir)) return null;
    $ext = get_image_format_extension();
    $thumbs = glob("$thumb_dir/*.$ext");
    if (empty($thumbs)) return null;
    $random_thumb = $thumbs[array_rand($thumbs)];
    $web_path = photo_root_web_path();
    return $web_path . str_replace('\\', '/', substr($random_thumb, strlen(realpath(PHOTO_ROOT))));
}

/**
 * Get a random thumbnail from anywhere within a year
 * Collects all thumbnails from all months in the year and picks one randomly
 * @param string $year 4-digit year
 * @return string|null Web path to random thumbnail or null if none found
 */
function get_random_thumbnail_from_year($year) {
    // Get all months in the year that have photos
    $months = get_months_in_year($year);
    if (empty($months)) return null;
    
    // Collect all thumbnails from all months in this year
    $all_thumbs = [];
    $ext = get_image_format_extension();
    foreach ($months as $month_num => $data) {
        $thumb_dir = PHOTO_ROOT . "/$year/$month_num/thumbs";
        if (file_exists($thumb_dir)) {
            $thumbs = glob("$thumb_dir/*.$ext");
            $all_thumbs = array_merge($all_thumbs, $thumbs);
        }
    }
    
    if (empty($all_thumbs)) return null;
    
    // Pick a random thumbnail from all available
    $random_thumb = $all_thumbs[array_rand($all_thumbs)];
    $web_path = photo_root_web_path();
    return $web_path . str_replace('\\', '/', substr($random_thumb, strlen(realpath(PHOTO_ROOT))));
}

// ========== RENDER FUNCTIONS ==========

function render_footer() {
    $year = date('Y');
    $owner = defined('GALLERY_OWNER') ? GALLERY_OWNER : 'Gallery Owner';
    $logout = '';
    if (
        defined('GALLERY_PASSWORD') && GALLERY_PASSWORD !== '' &&
        !empty($_SESSION['gallery_authenticated'])
    ) {
        $logout = '<p><a href="?logout=1">Logout</a></p>';
    }
    
    // Check if IGEX attribution should be shown (enabled by default)
    $show_igex = !defined('SHOW_ATTRIBUTION') || SHOW_ATTRIBUTION;
    $igex_attribution = '';
    if ($show_igex) {
        $igex_attribution = '<p style="font-size:0.8em; color:#666; margin-top:0.5rem;"><a href="https://github.com/josephbu/igex" style="color:#666; text-decoration:none;">Powered by IGEX</a></p>';
    }
    
    return <<<HTML
    <footer style="margin-top:2rem; padding:1rem 0; text-align:center; color:#888; font-size:0.95em;">
        &copy; $year. All rights reserved. $owner.
        $logout
        $igex_attribution
    </footer>
HTML;
}

/**
 * Render lightbox container and JavaScript for full-screen image viewing
 *
 * Output is included on gallery pages and progressively enhances
 * month galleries without breaking direct image links.
 *
 * @return string
 */
function render_lightbox_container() {
    ob_start(); ?>
    <div id="igex-lightbox" class="igex-lightbox" aria-hidden="true">
        <button type="button" class="igex-lightbox__close" aria-label="Close">&times;</button>
        <button type="button" class="igex-lightbox__nav igex-lightbox__nav--prev" aria-label="Previous photo">&#10094;</button>
        <button type="button" class="igex-lightbox__nav igex-lightbox__nav--next" aria-label="Next photo">&#10095;</button>
        <div class="igex-lightbox__inner">
            <img src="" alt="" class="igex-lightbox__image">
            <div class="igex-lightbox__caption">
                <span class="igex-lightbox__caption-text"></span>
                <a href="#" class="igex-lightbox__details-link">View details</a>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var overlay = document.getElementById('igex-lightbox');
            if (!overlay) return;

            var imgEl = overlay.querySelector('.igex-lightbox__image');
            var captionEl = overlay.querySelector('.igex-lightbox__caption-text');
            var detailsLinkEl = overlay.querySelector('.igex-lightbox__details-link');
            var closeBtn = overlay.querySelector('.igex-lightbox__close');
            var prevBtn = overlay.querySelector('.igex-lightbox__nav--prev');
            var nextBtn = overlay.querySelector('.igex-lightbox__nav--next');

            var items = [];
            var currentIndex = -1;
            var touchStartX = 0;
            var touchStartY = 0;

            function collectItems() {
                items = [];
                var links = document.querySelectorAll('.gallery a.gallery-item');
                links.forEach(function (link, index) {
                    var preview = link.getAttribute('data-preview') || link.querySelector('img')?.src;
                    var caption = link.getAttribute('data-caption') || link.querySelector('img')?.alt || '';
                    items.push({
                        link: link,
                        href: link.getAttribute('href'),
                        preview: preview,
                        caption: caption
                    });
                    link.dataset.igexIndex = String(index);
                });
            }

            function openAt(index) {
                if (!items.length || index < 0 || index >= items.length) return;
                currentIndex = index;
                var item = items[index];
                overlay.setAttribute('aria-hidden', 'false');
                overlay.classList.add('igex-lightbox--open');
                imgEl.src = item.preview;
                imgEl.alt = item.caption;
                captionEl.textContent = item.caption;
                detailsLinkEl.href = item.href;
                document.body.style.overflow = 'hidden';
            }

            function close() {
                overlay.setAttribute('aria-hidden', 'true');
                overlay.classList.remove('igex-lightbox--open');
                imgEl.src = '';
                document.body.style.overflow = '';
                currentIndex = -1;
            }

            function showNext(delta) {
                if (currentIndex === -1) return;
                var nextIndex = currentIndex + delta;
                if (nextIndex < 0 || nextIndex >= items.length) return;
                openAt(nextIndex);
            }

            document.addEventListener('click', function (e) {
                var link = e.target.closest('.gallery a.gallery-item');
                if (!link) return;
                collectItems();
                var idx = parseInt(link.dataset.igexIndex || '-1', 10);
                if (isNaN(idx) || idx < 0) return;
                e.preventDefault();
                openAt(idx);
            });

            closeBtn.addEventListener('click', function () { close(); });
            prevBtn.addEventListener('click', function () { showNext(-1); });
            nextBtn.addEventListener('click', function () { showNext(1); });

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    close();
                }
            });

            // Basic touch swipe support for mobile (left/right to navigate)
            overlay.addEventListener('touchstart', function (e) {
                if (!overlay.classList.contains('igex-lightbox--open')) return;
                if (!e.touches || e.touches.length === 0) return;
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
            }, { passive: true });

            overlay.addEventListener('touchend', function (e) {
                if (!overlay.classList.contains('igex-lightbox--open')) return;
                if (!e.changedTouches || e.changedTouches.length === 0) return;
                var touchEndX = e.changedTouches[0].clientX;
                var touchEndY = e.changedTouches[0].clientY;
                var dx = touchEndX - touchStartX;
                var dy = touchEndY - touchStartY;

                // Horizontal swipe with sufficient distance and dominance over vertical movement
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
                    if (dx < 0) {
                        showNext(1); // swipe left -> next
                    } else {
                        showNext(-1); // swipe right -> previous
                    }
                }
            }, { passive: true });

            document.addEventListener('keydown', function (e) {
                if (!overlay.classList.contains('igex-lightbox--open')) return;
                if (e.key === 'Escape') {
                    close();
                } else if (e.key === 'ArrowLeft') {
                    showNext(-1);
                } else if (e.key === 'ArrowRight') {
                    showNext(1);
                }
            });
        })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Render the main gallery page structure
 * Generates complete HTML document with head, body, and footer
 * @param string|null $year 4-digit year or null for year index
 * @param string|null $month_name Full month name or null for month index
 * @return string Complete HTML document
 */
function render_gallery($year = null, $month_name = null) {
    // Get configurable gallery title from config, with fallback
    $gallery_title = defined('GALLERY_TITLE') ? GALLERY_TITLE : 'Photo Gallery';
    
    // Generate dynamic title
    $title = $gallery_title;
    if ($year && $month_name) {
        $title = "$gallery_title - $year - $month_name";
    } elseif ($year) {
        $title = "$gallery_title - $year";
    }
    
    ob_start(); ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?></title>
        <?= gallery_styles() ?>
    </head>
    <body>
        <?= render_breadcrumb($year, $month_name) ?>
        <?= render_content($year, $month_name) ?>
        <?= render_lightbox_container() ?>
        <?= render_footer() ?>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generate breadcrumb navigation
 * Creates hierarchical navigation: Albums > Year > Month
 * @param string|null $year Current year
 * @param string|null $month_name Current month name
 * @return string HTML breadcrumb navigation
 */
function render_breadcrumb($year, $month_name) {
    $base = get_base_path();
    $parts = ['<a href="' . $base . '">Albums</a>'];
    if($year) {
        $parts[] = '<a href="' . $base . $year . '">' . htmlspecialchars($year) . '</a>';
        if($month_name) {
            $parts[] = '<a href="' . $base . $year . '/' . $month_name . '">' . htmlspecialchars($month_name) . '</a>';
        }
    }
    return '<div class="breadcrumb">' . implode(' &raquo; ', $parts) . '</div>';
}

/**
 * Route to appropriate content renderer based on URL parameters
 * @param string|null $year 4-digit year
 * @param string|null $month_name Full month name
 * @return string HTML content for the main area
 */
function render_content($year, $month_name) {
    if(!$year) return render_year_index();
    if(!$month_name) return render_month_index($year);
    return render_month_gallery($year, $month_name);
}

/**
 * Render the year index page showing all available years
 * Each year shows a random thumbnail from that year and month count
 * @return string HTML for year grid
 */
function render_year_index() {
    $years = array_filter(glob(PHOTO_ROOT . '/*', GLOB_ONLYDIR), 'is_dir');
    rsort($years);
    $base = get_base_path();
    ob_start(); ?>
    <div class="month-grid">
        <?php foreach($years as $year_path):
            $year = basename($year_path);
            $thumb_src = get_random_thumbnail_from_year($year);
        ?>
            <div class="month-card">
                <?php if($thumb_src): ?>
                 <a href="<?= htmlspecialchars($base . $year) ?>">
                     <img src="<?= htmlspecialchars($thumb_src) ?>" class="month-thumbnail" alt="">
                 </a>
                 <?php endif; ?>
                <?php $months = get_months_in_year($year); ?>
                <div class="overlay-text">
                        <h2 class="year-title"><a href="<?= htmlspecialchars($base . $year) ?>"><?= htmlspecialchars($year) ?></a></h2>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render the month index for a specific year
 * Shows all months in the year with random thumbnails and photo counts
 * @param string $year 4-digit year
 * @return string HTML for month grid
 */
function render_month_index($year) {
    $months = get_months_in_year($year);
    $base = get_base_path();
    ob_start(); ?>
    <div class="month-grid">
        <?php foreach($months as $month_num => $data):
            $month_name = month_num_to_name($month_num);
            $thumb_src = get_random_thumbnail($year, $month_num);
        ?>
            <div class="month-card">
                <?php if($thumb_src): ?>
                 <a href="<?= htmlspecialchars($base . $year . '/' . $month_name) ?>">
                     <img src="<?= htmlspecialchars($thumb_src) ?>" class="month-thumbnail" alt="">
                 </a>
                 <?php endif; ?>
                 <div class="overlay-text">
                     <h3 class="month-title">
                         <a href="<?= htmlspecialchars($base . $year . '/' . $month_name) ?>">
                             <?= htmlspecialchars($month_name) ?> <?= htmlspecialchars($year) ?>
                         </a>
                     </h3>
                 </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render the photo gallery for a specific month
 * Shows all photos in the month as clickable thumbnails
 * @param string $year 4-digit year
 * @param string $month_name Full month name
 * @return string HTML for photo grid
 */
function render_month_gallery($year, $month_name) {
    $month_num = month_name_to_num($month_name);
    $images = get_images($year, $month_num);
    $base = get_base_path();
    $ext = get_image_format_extension();
    ob_start(); ?>
    <div class="gallery">
        <?php foreach($images as $img):
            $web_path = photo_root_web_path();
            $slug = pathinfo($img, PATHINFO_FILENAME);
            $thumb = $web_path . "/$year/$month_num/thumbs/" . $slug . "." . $ext;
            $preview = $web_path . "/$year/$month_num/previews/" . $slug . "." . $ext;
            $image_link = $base . $year . '/' . $month_name . '/' . $slug;
            $caption = str_replace(['-', '_'], ' ', $slug);
        ?>
            <a href="<?= htmlspecialchars($image_link) ?>"
               class="gallery-item"
               data-preview="<?= htmlspecialchars($preview) ?>"
               data-caption="<?= htmlspecialchars($caption) ?>">
                <img src="<?= htmlspecialchars($thumb) ?>" loading="lazy" alt="<?= htmlspecialchars($caption) ?>">
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render individual image view with metadata
 * Shows full-size preview image and EXIF data from JSON metadata files
 * @param string $year 4-digit year
 * @param string $month_name Full month name
 * @param string $image Image filename without extension
 * @return string Complete HTML document for image view
 */
function render_image_view($year, $month_name, $image) {
    $month_num = month_name_to_num($month_name);
    $base = get_base_path();
    $ext = get_image_format_extension();
    $meta_file = PHOTO_ROOT . "/$year/$month_num/meta/" . pathinfo($image, PATHINFO_FILENAME) . ".json";
    $metadata = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
    $web_path = photo_root_web_path();
    $preview = $web_path . "/$year/$month_num/previews/" . pathinfo($image, PATHINFO_FILENAME) . "." . $ext;
    $back_link = $base . $year . '/' . $month_name;
    
    // Determine previous/next images within this month for navigation
    $images = get_images($year, $month_num);
    $currentIndex = array_search($image, $images, true);
    $totalImages = count($images);
    $positionLabel = '';
    $prevLink = null;
    $nextLink = null;
    $prevPreview = null;
    $nextPreview = null;

    if ($currentIndex !== false) {
        $humanIndex = $currentIndex + 1;
        $positionLabel = "Photo $humanIndex of $totalImages";

        if ($currentIndex > 0) {
            $prevSlug = $images[$currentIndex - 1];
            $prevLink = $base . $year . '/' . $month_name . '/' . $prevSlug;
            $prevPreview = $web_path . "/$year/$month_num/previews/" . $prevSlug . "." . $ext;
        }
        if ($currentIndex < $totalImages - 1) {
            $nextSlug = $images[$currentIndex + 1];
            $nextLink = $base . $year . '/' . $month_name . '/' . $nextSlug;
            $nextPreview = $web_path . "/$year/$month_num/previews/" . $nextSlug . "." . $ext;
        }
    }
    
    // Get configurable gallery title from config, with fallback
    $gallery_title = defined('GALLERY_TITLE') ? GALLERY_TITLE : 'Photo Gallery';
    
    // Generate page title with gallery title included
    $page_title = "$gallery_title - $year - $month_name - $image";
    
    // Format metadata for display
    $date_taken = !empty($metadata['datetime']) ? 
        date('F j, Y H:i', strtotime($metadata['datetime'])) : 'Unknown';
    
    // Handle exposure speed formatting (convert decimals to fractions)
    $exposure = !empty($metadata['exposure']) ?
        ((float)$metadata['exposure'] < 1 ? '1/' . round(1/(float)$metadata['exposure']) : $metadata['exposure']) : '';

    // Shutter speed is now pre-formatted, use as-is
    $shutter_speed = $metadata['shutter_speed'] ?? '';
    
    // Format camera settings for display
    $fnumber = !empty($metadata['fnumber']) ? 'ƒ/' . $metadata['fnumber'] : '';
    $iso = !empty($metadata['iso']) ? 'ISO ' . $metadata['iso'] : '';
    $focal = $metadata['focal_length'] ?? '';
    $camera = $metadata['camera'] ?? '';
    // Detail strip configuration
    $stripPosition = defined('DETAIL_STRIP_POSITION') ? DETAIL_STRIP_POSITION : 'bottom';
    $stripPosition = in_array($stripPosition, ['top', 'bottom', 'off'], true) ? $stripPosition : 'bottom';
    $showStrip = $totalImages > 1 && $stripPosition !== 'off';
    ob_start(); ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($page_title) ?></title>
        <?= gallery_styles() ?>
        <?php if ($prevPreview): ?>
            <link rel="preload" as="image" href="<?= htmlspecialchars($prevPreview) ?>">
        <?php endif; ?>
        <?php if ($nextPreview): ?>
            <link rel="preload" as="image" href="<?= htmlspecialchars($nextPreview) ?>">
        <?php endif; ?>
        <style>
            body { text-align: center; }
            img { max-width: 95vw; max-height: 80vh; margin: 2rem auto; border-radius: 8px; box-shadow: 0 2px 8px #0002; }
            .image-nav { display:flex; justify-content:space-between; align-items:center; max-width:900px; margin:0 auto 1rem; padding:0 1rem; font-size:0.95rem; }
            .image-nav a { text-decoration:none; }
            .image-position { opacity:0.8; }
            .detail-strip { max-width: 1000px; margin: 0.5rem auto 2rem; padding: 0 0.75rem; display:flex; gap:0.4rem; overflow-x:auto; }
            .detail-strip-thumb { display:block; flex:0 0 auto; border-radius:6px; overflow:hidden; border:2px solid transparent; opacity:0.7; }
            .detail-strip-thumb img { display:block; width:72px; height:72px; object-fit:cover; }
            .detail-strip-thumb.is-current { border-color:#64b5f6; opacity:1; }
        </style>
    </head>
    <body>
        <div class="breadcrumb">
            <a href="<?= htmlspecialchars($base) ?>">Albums</a> &raquo;
            <a href="<?= htmlspecialchars($base . $year) ?>"><?= htmlspecialchars($year) ?></a> &raquo;
            <a href="<?= htmlspecialchars($base . $year . '/' . $month_name) ?>"><?= htmlspecialchars($month_name) ?></a> &raquo;
            <?= htmlspecialchars($image) ?>
        </div>
        <p><a href="<?= htmlspecialchars($back_link) ?>">&larr; Back to Month</a></p>
        <?php if ($prevLink || $nextLink || $positionLabel): ?>
        <div class="image-nav">
            <div>
                <?php if ($prevLink): ?>
                    <a href="<?= htmlspecialchars($prevLink) ?>">&larr; Previous</a>
                <?php endif; ?>
            </div>
            <div class="image-position">
                <?= htmlspecialchars($positionLabel) ?>
            </div>
            <div>
                <?php if ($nextLink): ?>
                    <a href="<?= htmlspecialchars($nextLink) ?>">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($showStrip && $stripPosition === 'top'): ?>
        <div class="detail-strip" aria-label="Other photos in this month">
            <?php foreach ($images as $idx => $slug): 
                $thumbUrl = $web_path . "/$year/$month_num/thumbs/" . $slug . "." . $ext;
                $linkUrl = $base . $year . '/' . $month_name . '/' . $slug;
                $isCurrent = ($idx === $currentIndex);
            ?>
                <a href="<?= htmlspecialchars($linkUrl) ?>" class="detail-strip-thumb<?= $isCurrent ? ' is-current' : '' ?>">
                    <img src="<?= htmlspecialchars($thumbUrl) ?>" loading="lazy" alt="">
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <img src="<?= htmlspecialchars($preview) ?>" alt="">
        <?php if ($showStrip && $stripPosition === 'bottom'): ?>
        <div class="detail-strip" aria-label="Other photos in this month">
            <?php foreach ($images as $idx => $slug): 
                $thumbUrl = $web_path . "/$year/$month_num/thumbs/" . $slug . "." . $ext;
                $linkUrl = $base . $year . '/' . $month_name . '/' . $slug;
                $isCurrent = ($idx === $currentIndex);
            ?>
                <a href="<?= htmlspecialchars($linkUrl) ?>" class="detail-strip-thumb<?= $isCurrent ? ' is-current' : '' ?>">
                    <img src="<?= htmlspecialchars($thumbUrl) ?>" loading="lazy" alt="">
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="metadata-grid">
            <div class="metadata-item">
                <span class="metadata-label">Date Taken</span>
                <span class="metadata-value"><?= $date_taken ?></span>
            </div>
            <?php if($camera): ?>
            <div class="metadata-item">
                <span class="metadata-label">Camera</span>
                <span class="metadata-value"><?= htmlspecialchars($camera) ?></span>
            </div>
            <?php endif; ?>
            <?php if($focal || $exposure || $fnumber || $shutter_speed || $iso): ?>
            <div class="metadata-item">
                <span class="metadata-label">Settings</span>
                <span class="metadata-value">
                    <?= htmlspecialchars(trim("$focal $exposure $fnumber $shutter_speed $iso")) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($prevLink || $nextLink): ?>
        <script>
            (function() {
                var prevUrl = <?= $prevLink ? json_encode($prevLink) : 'null' ?>;
                var nextUrl = <?= $nextLink ? json_encode($nextLink) : 'null' ?>;
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowLeft' && prevUrl) {
                        window.location.href = prevUrl;
                    } else if (e.key === 'ArrowRight' && nextUrl) {
                        window.location.href = nextUrl;
                    }
                });
            })();
        </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

// ========== HELPER FUNCTIONS ==========

/**
 * Extract date/time from EXIF metadata for image sorting
 * Reads from pre-generated JSON metadata files
 * @param string $year 4-digit year
 * @param string $month_num Zero-padded month number
 * @param string $filename Image filename
 * @return int Unix timestamp or 0 if no date found
 */
function get_image_date($year, $month_num, $filename) {
    $meta_file = PHOTO_ROOT . "/$year/$month_num/meta/" . pathinfo($filename, PATHINFO_FILENAME) . ".json";
    
    if (!file_exists($meta_file)) {
        return 0; // Fallback to sorting missing metadata at end
    }
    
    $meta = json_decode(file_get_contents($meta_file), true);
    if (empty($meta['datetime'])) {
        return 0;
    }
    
    return strtotime($meta['datetime']);
}

/**
 * Get all months that contain photos for a given year
 * Scans the filesystem for month directories with preview images
 * @param string $year 4-digit year
 * @return array Associative array [month_num => ['count' => int, 'path' => string]]
 */
function get_months_in_year($year) {
    $months = [];
    $dirs = glob(PHOTO_ROOT . "/$year/*", GLOB_ONLYDIR);    
    $ext = get_image_format_extension();
    foreach ($dirs as $dir) {
        $month_num = basename($dir);
        // Only process directories with 2-digit month numbers (01-12)
        if (preg_match('/^\d{2}$/', $month_num)) {
            // Check previews directory instead of originals
            $previews_path = "$dir/previews";
            
            // Count previews in the configured format
            $count = file_exists($previews_path)
                ? count(glob("$previews_path/*.$ext"))
                : 0;
            if ($count > 0) {
                $months[$month_num] = [
                    'count' => $count,
                    'path' => $dir
                ];
            }
        }
    }
    // Sort months in descending order (December to January)
    krsort($months);
    return $months;
}

/**
 * Get all images for a specific year/month, sorted by date taken
 * Reads from previews directory and sorts by EXIF metadata dates
 * @param string $year 4-digit year
 * @param string $month_num Zero-padded month number
 * @return array Array of image filenames (without extensions)
 */
function get_images($year, $month_num) {
    $path = PHOTO_ROOT . "/$year/$month_num/previews";
    if (!file_exists($path)) return [];
    
    $images = [];
    $ext = get_image_format_extension();
    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot()) continue;
        
        // Only process files with the configured extension
        if (strtolower($file->getExtension()) === $ext) {
            $images[] = $file->getBasename('.' . $ext);
        }
    }

    // Sort by EXIF DateTimeOriginal from metadata (newest first)
    usort($images, function($a, $b) use ($year, $month_num) {
        $dateA = get_image_date($year, $month_num, $a);
        $dateB = get_image_date($year, $month_num, $b);
        return $dateB <=> $dateA; // Descending order
    });

    return $images;
}
?>