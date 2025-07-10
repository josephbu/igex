<?php
/**
 * Image Processor for Image Gallery EXpress (IGEX)
 * https://github.com/josephbu/igex
 * 
 * This script processes original images from a directory structure and creates
 * thumbnails, previews, and metadata files for a web photo gallery.
 * 
 * Expected directory structure:
 * - originals/YYYY/MM/filename.ext (source images)
 * - photos/YYYY/MM/thumbs/filename.webp (or .jpg) (square thumbnails)
 * - photos/YYYY/MM/previews/filename.webp (or .jpg) (scaled previews)
 * - photos/YYYY/MM/meta/filename.json (EXIF metadata)
 * 
 * Supported formats: JPEG, PNG, HEIC/HEIF
 * 
 * @author JB
 * @version 0.2
 */

require_once 'config.php';

/**
 * Main image processing class
 * 
 * Handles the conversion of original images into web-optimized formats
 * with proper metadata extraction and directory organization.
 */
class ImageProcessor {

    /**
     * Main entry point - processes all images in the originals directory
     * 
     * Recursively scans the ORIGINALS_ROOT directory and processes each
     * image file found, creating derivatives in the appropriate structure.
     */
    public function processNewImages() {
        // Create recursive iterator to scan all subdirectories
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ORIGINALS_ROOT, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        // Process each file found
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            
            $this->processFile($file);
        }
    }

    /**
     * Process a single image file
     * 
     * Validates the file type, extracts path information, and creates
     * all derivative images and metadata files.
     * 
     * @param SplFileInfo $file The file to process
     */
    private function processFile(SplFileInfo $file) {
        $source = $file->getPathname();
        $ext = strtolower($file->getExtension());
        
        // Skip files that aren't allowed image types
        if (!in_array($ext, ALLOWED_TYPES)) {
            error_log("Skipped non-allowed type: $source");
            return;
        }

        try {
            // Extract year/month from path structure: originals/YYYY/MM/filename.ext
            $relativePath = str_replace(
                ORIGINALS_ROOT . DIRECTORY_SEPARATOR, 
                '', 
                $file->getPathname()
            );
            $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
            
            // Validate path structure
            if (count($pathParts) < 2) {
                error_log("Invalid path structure: $source");
                return;
            }

            $year = $pathParts[0];
            $month = $pathParts[1];
            $filename = pathinfo($source, PATHINFO_FILENAME);
            
            // Build destination path
            $destBase = PHOTO_ROOT . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
            
            // Create all derivative files
            $this->createDerivatives($source, $destBase, $filename);
        } catch (Exception $e) {
            error_log("Error processing $source: " . $e->getMessage());
        }
    }

    /**
     * Create all derivative files for an image
     * 
     * This is the main processing pipeline that:
     * 1. Creates output directories
     * 2. Loads and orients the image
     * 3. Extracts and saves metadata
     * 4. Creates thumbnail and preview images
     * 
     * @param string $source Source image path
     * @param string $destBase Destination directory base path
     * @param string $filename Base filename (without extension)
     */
    private function createDerivatives($source, $destBase, $filename) {
        // Create necessary subdirectories
        $this->createDirectories($destBase);
        
        // Load the source image
        $image = $this->loadImage($source);
        if (!$image) {
            throw new Exception("Failed to load image: $source");
        }
    
        // Extract EXIF data and apply orientation correction
        $exif = $this->getExifData($source);
        $image = $this->applyOrientation($image, $exif);
        
        // Save metadata to JSON file
        $this->saveMetadata($source, $destBase . 'meta/', $filename, $exif);
    
        // Create derivative images
        $this->createThumbnail($image, $destBase, $filename);
        $this->createPreview($image, $destBase, $filename);
    
        // Clean up memory
        $this->destroyImage($image);
    }

    /**
     * Clean up image resources
     * 
     * Properly destroys both ImageMagick and GD resources
     * 
     * @param resource|Imagick $image Image resource or Imagick object
     */
    private function destroyImage($image) {
        if ($image instanceof Imagick) {
            $image->destroy();
        } else {
            imagedestroy($image);
        }
    }

    /**
     * Create necessary output directories
     * 
     * Creates thumbs/, previews/, and meta/ subdirectories if they don't exist.
     * 
     * @param string $destBase Base destination path
     */
    private function createDirectories($destBase) {
        $dirs = ['thumbs', 'previews', 'meta'];
        foreach ($dirs as $dir) {
            $path = $destBase . $dir;
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Safely round numeric values, handling EXIF fraction format
     * 
     * EXIF data often contains fractions like "1/60" which need to be
     * converted to decimal values before rounding.
     * 
     * @param mixed $value Value to round (can be fraction string or number)
     * @param int $precision Number of decimal places
     * @return float|null Rounded value or null if not numeric
     */
    private function safeRound($value, $precision = 1) {
        // Handle EXIF fraction format (e.g., "1/60")
        if (is_string($value) && strpos($value, '/') !== false) {
            list($numerator, $denominator) = explode('/', $value, 2);
            if ($denominator != 0) {
                $value = $numerator / $denominator;
            }
        }
        return is_numeric($value) ? round((float)$value, $precision) : null;
    }

    /**
     * Extract and save image metadata to JSON file
     * 
     * Extracts relevant EXIF data including camera info, exposure settings,
     * and timestamps. Handles both regular EXIF and HEIC metadata formats.
     * 
     * @param string $source Source image path
     * @param string $metaDir Metadata output directory
     * @param string $filename Base filename
     * @param array $exif EXIF data array
     */
    private function saveMetadata($source, $metaDir, $filename, $exif) {
        // Check if we have HEIC format (requires special handling)
        $mime = mime_content_type($source);
        $isHeic = in_array($mime, ['image/heic', 'image/heif']);
    
        // Helper function to get EXIF values with HEIC fallbacks
        $getValue = function($key) use ($exif, $isHeic) {
            if ($isHeic) {
                // Try both prefixed and non-prefixed versions for HEIC
                return $exif[$key] ?? $exif["exif:$key"] ?? null;
            }
            return $exif[$key] ?? null;
        };
    
        // Extract metadata with fallbacks
        $datetime = $getValue('DateTimeOriginal') ?? $getValue('DateTime') ?? date('Y:m:d H:i:s', filemtime($source));
        $make = $getValue('Make');
        $model = $getValue('Model');
        $exposure = $getValue('ExposureTime');
        
        // Get shutter speed from EXIF - PHP uses ExposureTime field
        $shutterSpeed = $getValue('ExposureTime');
        
        $fnumber = $getValue('FNumber');
        $iso = $getValue('ISOSpeedRatings') ?? $getValue('ISO') ?? $getValue('PhotographicSensitivity');
        
        // Handle focal length - try the 35mm format first, then regular focal length
        $focalLength35mm = $getValue('FocalLengthIn35mmFilm') ?? $getValue('FocalLengthIn35mmFormat');
        $focalLength = $focalLength35mm ?? $getValue('FocalLength');
    
        // Clean up camera model - remove manufacturer prefix if present
        $camera = $model ?? '';
        if ($make && $model && stripos($model, $make) === 0) {
            // Remove make from beginning of model (case-insensitive)
            $camera = trim(substr($model, strlen($make)));
        }
    
        // Build metadata array
        $metadata = [
            'datetime' => $datetime,
            'camera' => $camera,
            'exposure' => $this->safeRound($exposure),
            'shutter_speed' => $shutterSpeed, // Use raw value directly
            'fnumber' => $this->safeRound($fnumber, 1),
            'iso' => $this->safeRound($iso),
            'focal_length' => $focalLength ? $this->safeRound($focalLength) . 'mm' : null
        ];
    
        // Save to JSON file (filter out null values)
        file_put_contents(
            $metaDir . $filename . '.json',
            json_encode(array_filter($metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Load an image from file, with processor preference
     * 
     * Uses the IMAGE_PROCESSOR setting to determine which processor to use.
     * 
     * @param string $path Path to image file
     * @return resource|Imagick|false Image resource/object or false on failure
     */
    private function loadImage($path) {
        $mime = mime_content_type($path);
        $processor = strtolower(IMAGE_PROCESSOR);
        $isHeic = in_array($mime, ['image/heic', 'image/heif']);
        
        // Force ImageMagick if requested
        if ($processor === 'imagemagick') {
            return $this->loadImageMagick($path);
        }
        
        // Force GD if requested
        if ($processor === 'gd') {
            if ($isHeic) {
                throw new Exception("HEIC/HEIF files are not supported with GD processor. Use 'imagemagick' or 'auto' mode.");
            }
            return $this->loadImageGD($path);
        }
        
        // Auto mode - use GD for non-HEIC files (faster), ImageMagick for HEIC
        if ($isHeic) {
            if (!extension_loaded('imagick')) {
                throw new Exception("HEIC/HEIF files require ImageMagick extension");
            }
            return $this->loadImageMagick($path);
        } else {
            // Use GD for JPEG/PNG (faster)
            $image = $this->loadImageGD($path);
            if ($image !== false) {
                return $image;
            }
            
            // Fallback to ImageMagick if GD fails
            if (extension_loaded('imagick')) {
                return $this->loadImageMagick($path);
            }
        }
        
        return false;
    }

    /**
     * Load image using ImageMagick
     * 
     * @param string $path Path to image file
     * @return Imagick|false Imagick object or false on failure
     */
    private function loadImageMagick($path) {
        if (!extension_loaded('imagick')) {
            return false;
        }
        
        try {
            $imagick = new Imagick();
            $imagick->readImage($path);
            
            // Convert to RGB colorspace for consistency
            $imagick->setImageColorspace(Imagick::COLORSPACE_RGB);
            
            return $imagick;
        } catch (ImagickException $e) {
            error_log("ImageMagick failed for $path: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load image using GD
     * 
     * @param string $path Path to image file
     * @return resource|false GD image resource or false on failure
     */
    private function loadImageGD($path) {
        $mime = mime_content_type($path);
        
        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                $img = imagecreatefrompng($path);
                if ($img !== false) {
                    imagealphablending($img, true);
                    imagesavealpha($img, true);
                }
                return $img;
            case 'image/heic':
            case 'image/heif':
                error_log("HEIC/HEIF files require ImageMagick");
                return false;
            default:
                return false;
        }
    }

    /**
     * Extract EXIF data from image file
     * 
     * Handles both standard EXIF (for JPEG/PNG) and ImageMagick-based
     * extraction for HEIC files. Returns a normalized array of metadata.
     * 
     * @param string $source Path to image file
     * @return array EXIF data array
     */
    private function getExifData($source) {
        $mime = mime_content_type($source);
        
        // Handle HEIC separately with better property extraction
        if (in_array($mime, ['image/heic', 'image/heif']) && extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->readImage($source);
                
                // Get all EXIF properties
                $exifProps = $imagick->getImageProperties('exif:*');
                
                // Also try to get XMP and other metadata
                $profiles = $imagick->getImageProfiles('*', false);
                
                // Convert to format expected by saveMetadata
                $standardExif = [];
                foreach ($exifProps as $key => $value) {
                    // Remove 'exif:' prefix for consistency
                    $cleanKey = str_replace('exif:', '', $key);
                    $standardExif[$cleanKey] = $value;
                    // Also keep the prefixed version for backward compatibility
                    $standardExif[$key] = $value;
                }
                
                // Handle orientation for HEIC
                if (isset($standardExif['Orientation'])) {
                    $standardExif['Orientation'] = intval($standardExif['Orientation']);
                }
                
                $imagick->destroy();
                return $standardExif;
            } catch (ImagickException $e) {
                error_log("HEIC EXIF extraction error: " . $e->getMessage());
                return [];
            }
        }
        
        // Default EXIF reader for JPEG/PNG
        return @exif_read_data($source) ?: [];
    }

    /**
     * Apply EXIF orientation correction to image
     * 
     * Handles both ImageMagick and GD image resources.
     * 
     * @param resource|Imagick $image Image resource or Imagick object
     * @param array $exif EXIF data array
     * @return resource|Imagick Oriented image resource/object
     */
    private function applyOrientation($image, $exif) {
        $orientation = $exif['Orientation'] ?? $exif['exif:Orientation'] ?? null;
        
        if (!$orientation) {
            return $image;
        }
        
        $orientationInt = intval($orientation);
        
        if ($image instanceof Imagick) {
            // Check if autoOrientImage() method exists to avoid fatal errors.
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            } else {
                // Manual orientation as a fallback for older Imagick versions.
                switch ($orientationInt) {
                    case 3: $image->rotateImage(new ImagickPixel('transparent'), 180); break;
                    case 6: $image->rotateImage(new ImagickPixel('transparent'), 90); break;
                    case 8: $image->rotateImage(new ImagickPixel('transparent'), -90); break;
                }
            }
        } else {
            // GD orientation handling
            switch ($orientationInt) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0); break;
            }
        }
        
        return $image;
    }

    /**
     * Create square thumbnail image
     * 
     * Creates a square thumbnail by cropping the center of the image
     * and resizing to THUMB_WIDTH x THUMB_WIDTH pixels.
     * Handles both ImageMagick and GD resources.
     * 
     * @param resource|Imagick $image Image resource or Imagick object
     * @param string $destBase Destination directory base path
     * @param string $filename Base filename
     */
    private function createThumbnail($image, $destBase, $filename) {
        // Get format from config, default to jpg
        $format = defined('IMAGE_FORMAT') ? strtolower(IMAGE_FORMAT) : 'jpg';
        $ext = ($format === 'webp') ? 'webp' : 'jpg';
        $thumbnailPath = $destBase . 'thumbs/' . $filename . '.' . $ext;
        
        if ($image instanceof Imagick) {
            // ImageMagick processing
            $thumb = clone $image;
            
            // Get dimensions
            $width = $thumb->getImageWidth();
            $height = $thumb->getImageHeight();
            $minDim = min($width, $height);
            
            // Calculate crop box (center square) - ROUND values to the nearest int
            $src_x = (int) round(($width - $minDim) / 2);
            $src_y = (int) round(($height - $minDim) / 2);
            
            // Crop to square
            $thumb->cropImage($minDim, $minDim, $src_x, $src_y);
            
            // Resize to thumbnail size
            $thumb->resizeImage(THUMB_WIDTH, THUMB_WIDTH, Imagick::FILTER_LANCZOS, 1);
            
            // Set format and quality
            $thumb->setImageFormat($format);
            $thumb->setImageCompressionQuality(THUMB_QUALITY);
            
            // Save
            $thumb->writeImage($thumbnailPath);
            $thumb->destroy();
            
        } else {
            // GD processing
            $width = imagesx($image);
            $height = imagesy($image);
            $minDim = min($width, $height);
            
            // Calculate crop box (center square) and cast to int
            $src_x = (int)(($width - $minDim) / 2);
            $src_y = (int)(($height - $minDim) / 2);
            $minDim = (int)$minDim;
            
            // Create square thumbnail
            $thumb = imagecreatetruecolor(THUMB_WIDTH, THUMB_WIDTH);
            imagecopyresampled(
                $thumb, $image,
                0, 0,                           // Destination position
                $src_x, $src_y,                 // Source crop position
                THUMB_WIDTH, THUMB_WIDTH,       // Destination size
                $minDim, $minDim                // Source crop size
            );
            
            // Save thumbnail in the chosen format
            if ($format === 'webp') {
                imagewebp($thumb, $thumbnailPath, THUMB_QUALITY);
            } else {
                imagejpeg($thumb, $thumbnailPath, THUMB_QUALITY);
            }
            imagedestroy($thumb);
        }
    }

    /**
     * Create preview image
     * 
     * Creates a web-optimized preview image scaled to PREVIEW_SIZE
     * on the longest dimension while maintaining aspect ratio.
     * Handles both ImageMagick and GD resources.
     * 
     * @param resource|Imagick $image Image resource or Imagick object
     * @param string $destBase Destination directory base path
     * @param string $filename Base filename
     */
    private function createPreview($image, $destBase, $filename) {
        // Get format from config, default to jpg
        $format = defined('IMAGE_FORMAT') ? strtolower(IMAGE_FORMAT) : 'jpg';
        $ext = ($format === 'webp') ? 'webp' : 'jpg';
        $previewPath = $destBase . 'previews/' . $filename . '.' . $ext;
        
        if ($image instanceof Imagick) {
            // ImageMagick processing
            $preview = clone $image;
            
            // Get dimensions
            $width = $preview->getImageWidth();
            $height = $preview->getImageHeight();
            
            // Calculate new dimensions based on longest side
            if ($width > $height) {
                // Landscape: scale by width
                $preview->resizeImage(PREVIEW_SIZE, 0, Imagick::FILTER_LANCZOS, 1);
            } else {
                // Portrait or square: scale by height
                $preview->resizeImage(0, PREVIEW_SIZE, Imagick::FILTER_LANCZOS, 1);
            }
            
            // Set format and quality
            $preview->setImageFormat($format);
            $preview->setImageCompressionQuality(PREVIEW_QUALITY);
            
            // Save
            $preview->writeImage($previewPath);
            $preview->destroy();
            
        } else {
            // GD processing
            $width = imagesx($image);
            $height = imagesy($image);
            
            // Calculate new dimensions based on longest side
            if ($width > $height) {
                // Landscape: scale by width
                $newWidth = PREVIEW_SIZE;
                $newHeight = (int) round(($height / $width) * PREVIEW_SIZE);
            } else {
                // Portrait or square: scale by height
                $newHeight = PREVIEW_SIZE;
                $newWidth = (int) round(($width / $height) * PREVIEW_SIZE);
            }
            
            // Create preview image using imagecopyresampled for better quality
            $preview = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled(
                $preview, $image,
                0, 0,                           // Destination position
                0, 0,                           // Source position
                $newWidth, $newHeight,          // Destination size
                $width, $height                 // Source size
            );
            
            // Save preview in the chosen format
            if ($format === 'webp') {
                imagewebp($preview, $previewPath, PREVIEW_QUALITY);
            } else {
                imagejpeg($preview, $previewPath, PREVIEW_QUALITY);
            }
            imagedestroy($preview);
        }
    }
}

// ============================================================================
// SCRIPT EXECUTION
// ============================================================================

// Create processor instance and run
$processor = new ImageProcessor();
$processor->processNewImages();
echo "Processing completed!\n";
?>