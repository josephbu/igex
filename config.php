<?php
// These are the options that you probably want to update
define('GALLERY_OWNER', 'Your Name');
define('GALLERY_TITLE', 'IGEX Gallery');
define('GALLERY_THEME', 'dark'); // dark or light
//define('GALLERY_PASSWORD', 'password'); // Uncomment and update if needed
define('ALLOWED_TYPES', ['jpg', 'jpeg', 'png', 'heic', 'heif']);
// Generally don't need to touch these
define('THUMB_WIDTH', 400); // 2x for retina/high-DPI screens
define('PREVIEW_SIZE', 1200); // Applied to longest dimension (width or height)
define('THUMB_QUALITY', 85);   // Default thumb quality (1-100)
define('PREVIEW_QUALITY', 90); // Default preview quality (1-100)
define('SHOW_ATTRIBUTION', true); // Set to false to disable link to IGEX
define('IMAGE_PROCESSOR', 'auto'); // Options: 'auto' (GD for JPEG/PNG, ImageMagick for HEIC), 'imagemagick' (all formats), 'gd' (JPEG/PNG only)
// Shouldn't need to edit below here
define('ORIGINALS_ROOT', __DIR__ . '/originals');
define('PHOTO_ROOT', __DIR__ . '/photos');
?>