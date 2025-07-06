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
 * @version 0.1
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
    $thumbs = glob("$thumb_dir/*.jpg");
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
    foreach ($months as $month_num => $data) {
        $thumb_dir = PHOTO_ROOT . "/$year/$month_num/thumbs";
        if (file_exists($thumb_dir)) {
            $thumbs = glob("$thumb_dir/*.jpg");
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
    ob_start(); ?>
    <div class="gallery">
        <?php foreach($images as $img):
            $web_path = photo_root_web_path();
            $thumb = $web_path . "/$year/$month_num/thumbs/" . pathinfo($img, PATHINFO_FILENAME) . ".jpg";
            $image_link = $base . $year . '/' . $month_name . '/' . pathinfo($img, PATHINFO_FILENAME);
        ?>
            <a href="<?= htmlspecialchars($image_link) ?>">
                <img src="<?= htmlspecialchars($thumb) ?>" loading="lazy" alt="">
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
    $meta_file = PHOTO_ROOT . "/$year/$month_num/meta/" . pathinfo($image, PATHINFO_FILENAME) . ".json";
    $metadata = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
    $web_path = photo_root_web_path();
    $preview = $web_path . "/$year/$month_num/previews/" . pathinfo($image, PATHINFO_FILENAME) . ".jpg";
    $back_link = $base . $year . '/' . $month_name;
    
    // Get configurable gallery title from config, with fallback
    $gallery_title = defined('GALLERY_TITLE') ? GALLERY_TITLE : 'Photo Gallery';
    
    // Generate page title with gallery title included
    $page_title = "$gallery_title - $year - $month_name - $image";
    
    // Format metadata for display
    $date_taken = !empty($metadata['datetime']) ? 
        date('F j, Y H:i', strtotime($metadata['datetime'])) : 'Unknown';
    
    // Handle exposure/shutter speed formatting (convert decimals to fractions)
    $exposure = !empty($metadata['exposure']) ?
        ((float)$metadata['exposure'] < 1 ? '1/' . round(1/(float)$metadata['exposure']) : $metadata['exposure']) : '';
    $shutter_speed = !empty($metadata['shutter_speed']) ?
        ((float)$metadata['shutter_speed'] < 1 ? '1/' . round(1/(float)$metadata['shutter_speed']) : $metadata['shutter_speed']) : '';
    
    // Format camera settings for display
    $fnumber = !empty($metadata['fnumber']) ? 'ƒ/' . $metadata['fnumber'] : '';
    $iso = !empty($metadata['iso']) ? 'ISO ' . $metadata['iso'] : '';
    $focal = $metadata['focal_length'] ?? '';
    $camera = $metadata['camera'] ?? '';
    ob_start(); ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($page_title) ?></title>
        <?= gallery_styles() ?>
        <style>
            body { text-align: center; }
            img { max-width: 95vw; max-height: 80vh; margin: 2rem auto; border-radius: 8px; box-shadow: 0 2px 8px #0002; }
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
        <img src="<?= htmlspecialchars($preview) ?>" alt="">
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
            <?php if($focal || $exposure || $shutter_speed || $fnumber || $iso): ?>
            <div class="metadata-item">
                <span class="metadata-label">Settings</span>
                <span class="metadata-value">
                    <?= htmlspecialchars(trim("$focal $exposure $fnumber $iso")) ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
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
    foreach ($dirs as $dir) {
        $month_num = basename($dir);
        // Only process directories with 2-digit month numbers (01-12)
        if (preg_match('/^\d{2}$/', $month_num)) {
            // Check previews directory instead of originals
            $previews_path = "$dir/previews";
            
            // Count JPG previews (all processed images)
            $count = file_exists($previews_path) 
                ? count(glob("$previews_path/*.jpg")) 
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
    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot()) continue;
        $ext = strtolower($file->getExtension());
        
        // Only process JPG previews (generated by image processor)
        if ($ext === 'jpg') {
            $images[] = $file->getBasename('.jpg');
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