<?php
require_once 'config.php';

class ImageProcessor {

    public function processNewImages() {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ORIGINALS_ROOT, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            
            $this->processFile($file);
        }
    }

    private function processFile(SplFileInfo $file) {
        $source = $file->getPathname();
        $ext = strtolower($file->getExtension());
        
        if (!in_array($ext, ALLOWED_TYPES)) {
            error_log("Skipped non-allowed type: $source");
            return;
        }

        try {
            // Get year/month from path: originals/YYYY/MM/filename.ext
            $relativePath = str_replace(
                ORIGINALS_ROOT . DIRECTORY_SEPARATOR, 
                '', 
                $file->getPathname()
            );
            $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
            
            if (count($pathParts) < 2) {
                error_log("Invalid path structure: $source");
                return;
            }

            $year = $pathParts[0];
            $month = $pathParts[1];
            $filename = pathinfo($source, PATHINFO_FILENAME);
            
            $destBase = PHOTO_ROOT . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR;
            
            $this->createDerivatives($source, $destBase, $filename);
        } catch (Exception $e) {
            error_log("Error processing $source: " . $e->getMessage());
        }
    }

    private function createDerivatives($source, $destBase, $filename) {
        $this->createDirectories($destBase);
        
        $image = $this->loadImage($source);
        if (!$image) {
            throw new Exception("Failed to load image: $source");
        }

        // Get EXIF data using the proper method
        $exif = $this->getExifData($source);
        $image = $this->applyOrientation($image, $exif);
        $this->saveMetadata($source, $destBase . 'meta/', $filename, $exif);

        $this->createThumbnail($image, $destBase, $filename);
        $this->createPreview($image, $destBase, $filename);

        imagedestroy($image);
    }

    private function createDirectories($destBase) {
        $dirs = ['thumbs', 'previews', 'meta'];
        foreach ($dirs as $dir) {
            $path = $destBase . $dir;
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function safeRound($value, $precision = 1) {
        if (is_string($value) && strpos($value, '/') !== false) {
            list($numerator, $denominator) = explode('/', $value, 2);
            if ($denominator != 0) {
                $value = $numerator / $denominator;
            }
        }
        return is_numeric($value) ? round((float)$value, $precision) : null;
    }

    private function saveMetadata($source, $metaDir, $filename, $exif) {
        // Check if we have HEIC format
        $mime = mime_content_type($source);
        $isHeic = in_array($mime, ['image/heic', 'image/heif']);
    
        // For HEIC, try both prefixed and non-prefixed keys
        $getValue = function($key) use ($exif, $isHeic) {
            if ($isHeic) {
                // Try both prefixed and non-prefixed versions
                return $exif[$key] ?? $exif["exif:$key"] ?? null;
            }
            return $exif[$key] ?? null;
        };
    
        // Extract metadata with fallbacks
        $datetime = $getValue('DateTimeOriginal') ?? $getValue('DateTime') ?? date('Y:m:d H:i:s', filemtime($source));
        $make = $getValue('Make');
        $model = $getValue('Model');
        $lens = $getValue('LensModel') ?? $getValue('UndefinedTag:0xA434');
        $exposure = $getValue('ExposureTime');
        $shutterSpeed = $getValue('ShutterSpeedValue');
        $fnumber = $getValue('FNumber');
        $iso = $getValue('ISOSpeedRatings') ?? $getValue('ISO') ?? $getValue('PhotographicSensitivity');
        $focalLength = $getValue('FocalLength');
    
        // Clean up camera model - remove manufacturer prefix if present
        $camera = $model ?? '';
        if ($make && $model && stripos($model, $make) === 0) {
            // Remove make from beginning of model (case-insensitive)
            $camera = trim(substr($model, strlen($make)));
        }
    
        $metadata = [
            'datetime' => $datetime,
            'camera' => $camera,
            'lens' => $lens,
            'exposure' => $this->safeRound($exposure),
            'shutter_speed' => $this->safeRound($shutterSpeed),
            'fnumber' => $this->safeRound($fnumber, 1),
            'iso' => $this->safeRound($iso),
            'focal_length' => $focalLength ? $this->safeRound($focalLength) . 'mm' : null
        ];
    
        file_put_contents(
            $metaDir . $filename . '.json',
            json_encode(array_filter($metadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function loadImage($path) {
        $mime = mime_content_type($path);
        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                $img = imagecreatefrompng($path);
                imagealphablending($img, true);
                imagesavealpha($img, true);
                return $img;
            case 'image/heic':
            case 'image/heif':
                return $this->loadHeic($path);                
            default:
                return false;
        }
    }

    private function loadHeic($path) {
        if (!extension_loaded('imagick')) {
            throw new Exception("Imagick extension not available for HEIC processing");
        }
        
        try {
            $imagick = new Imagick();
            $imagick->readImage($path);
            $imagick->setImageFormat('jpeg');
            $blob = $imagick->getImageBlob();
            return imagecreatefromstring($blob);
        } catch (ImagickException $e) {
            error_log("HEIC processing error: " . $e->getMessage());
            return false;
        }
    }

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

    private function applyOrientation($image, $exif) {
        $orientation = $exif['Orientation'] ?? $exif['exif:Orientation'] ?? null;
        if ($orientation) {
            switch (intval($orientation)) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0); break;
            }
        }
        return $image;
    }

    private function createThumbnail($image, $destBase, $filename) {
        $width = imagesx($image);
        $height = imagesy($image);
        $minDim = min($width, $height);
    
        // Calculate crop box (center square) and cast to int
        $src_x = (int)(($width - $minDim) / 2);
        $src_y = (int)(($height - $minDim) / 2);
        $minDim = (int)$minDim;
    
        $thumb = imagecreatetruecolor(THUMB_WIDTH, THUMB_WIDTH);
        imagecopyresampled(
            $thumb, $image,
            0, 0,
            $src_x, $src_y,
            THUMB_WIDTH, THUMB_WIDTH,
            $minDim, $minDim
        );
        imagejpeg($thumb, $destBase . 'thumbs/' . $filename . '.jpg', THUMB_QUALITY);
        imagedestroy($thumb);
    }

    private function createPreview($image, $destBase, $filename) {
        $preview = imagescale($image, PREVIEW_WIDTH);
        imagejpeg($preview, $destBase . 'previews/' . $filename . '.jpg', PREVIEW_QUALITY);
        imagedestroy($preview);
    }
}

// Run processor
$processor = new ImageProcessor();
$processor->processNewImages();
echo "Processing completed!\n";
?>
