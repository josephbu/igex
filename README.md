# Image Gallery EXpress (IGEX)

A lightweight, self-hosted PHP photo gallery that automatically organizes your images by year and month. Perfect for photographers and anyone who wants a clean, simple way to showcase their photo collections online.

## Features

- **Automatic Organization**: Organizes photos by year/month structure
- **Multiple Format Support**: JPEG, PNG, HEIC/HEIF compatibility
- **Automatic Processing**: Generates thumbnails, previews, and extracts EXIF metadata
- **Responsive Design**: Works beautifully on desktop and mobile devices
- **Password Protection**: Optional gallery-wide password protection
- **SEO-Friendly URLs**: Clean URL structure (`/2024/January/photo-name`)
- **EXIF Metadata Display**: Shows camera settings, date taken, and equipment info
- **Dual Themes**: Dark and light theme options
- **Fast Loading**: Optimized thumbnails and progressive image loading

## Screenshot Preview

The gallery displays photos in a clean grid layout with:
- Year/month navigation structure
- Square thumbnails for consistent layout
- Individual photo pages with full metadata
- Breadcrumb navigation

## Quick Start

### Requirements

- PHP 8.4+ with GD extension
- Web server (Apache/Nginx) with mod_rewrite
- Optional: ImageMagick extension for HEIC support

### Installation

1. **Download and Extract**
   ```bash
   git clone https://github.com/josephbu/igex.git
   cd igex
   ```

2. **Configure Web Server**
   - Copy `.htaccess` to web root(Apache)
   - Ensure mod_rewrite is enabled

3. **Set Up Directory Structure**
   ```
   your-gallery/
   ├── originals/          # Your source photos go here
   │   ├── 2024/
   │   │   ├── 01/         # January
   │   │   ├── 02/         # February
   │   │   └── ...
   │   └── 2023/
   ├── photos/             # Generated thumbnails/previews (auto-created)
   ├── css/                # CSS files here
   ├── config.php          # Configuration file
   ├── processor.php       # Image processing script
   └── index.php           # Main gallery application
   ```

4. **Configure Your Gallery**
   Edit `config.php`:
   ```php
   define('GALLERY_OWNER', 'Your Name');
   define('GALLERY_TITLE', 'My Photo Gallery');
   define('GALLERY_THEME', 'dark'); // or 'light'
   // define('GALLERY_PASSWORD', 'your-password'); // Uncomment for password protection
   ```

5. **Add Your Photos**
   Place your photos in the `originals/` directory using the year/month structure:
   ```
   originals/
   ├── 2024/
   │   ├── 01/
   │   │   ├── IMG_001.jpg
   │   │   ├── IMG_002.heic
   │   │   └── vacation_photo.png
   │   └── 02/
   │       └── IMG_003.jpg
   ```

6. **Process Images**
   Run the processor to generate thumbnails and metadata:
   ```bash
   php processor.php
   ```

7. **Access Your Gallery**
   Visit your website - the gallery will be available at your domain root or subdirectory.

## Configuration Options

### Basic Settings (`config.php`)

| Setting | Description | Default |
|---------|-------------|---------|
| `GALLERY_OWNER` | Copyright owner name | 'Your Name' |
| `GALLERY_TITLE` | Gallery title shown in browser | 'IGEX Gallery' |
| `GALLERY_THEME` | Theme: 'dark' or 'light' | 'dark' |
| `GALLERY_PASSWORD` | Optional password protection | Not set |
| `ALLOWED_TYPES` | Supported file extensions | jpg, jpeg, png, heic, heif |

### Advanced Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `THUMB_WIDTH` | Thumbnail size (square) | 400px |
| `PREVIEW_SIZE` | Preview image max size (longest dimension) | 1200px |
| `THUMB_QUALITY` | Thumbnail JPEG quality (1-100) | 85 |
| `PREVIEW_QUALITY` | Preview JPEG quality (1-100) | 90 |

## Image Processing

The `processor.php` script handles:

- **Thumbnail Generation**: Square thumbnails for grid display
- **Preview Creation**: Web-optimized full-size previews
- **EXIF Extraction**: Camera metadata, settings, and timestamps
- **Format Conversion**: Converts HEIC/HEIF to web-compatible JPEG (Requires ImageMagick)
- **Orientation Correction**: Automatically rotates images based on EXIF data

### Processing New Images

After adding photos to the `originals/` directory:

```bash
php processor.php
```

The processor creates:
```
photos/
└── 2024/
    └── 01/
        ├── thumbs/
        │   ├── IMG_001.jpg    # Square thumbnails
        │   └── IMG_002.jpg
        ├── previews/
        │   ├── IMG_001.jpg    # Full-size previews
        │   └── IMG_002.jpg
        └── meta/
            ├── IMG_001.json   # EXIF metadata
            └── IMG_002.json
```

Photos can be removed from the `originals/` directory once the processor has been run. This can help save space in your web hosting as you don't need to keep a copy of the originals. Obviously don't delete them from your normal photo storage location on NAS in triplicate ;-)

## Customization

### Themes

Two built-in themes are available:
- **Dark Theme** (`dark.css`): Dark background with light text
- **Light Theme** (`light.css`): Light background with dark text

Set your preferred theme in `config.php`:
```php
define('GALLERY_THEME', 'dark'); // or 'light'
```

### Custom Styling

Modify the CSS files in the `css/` directory to customize appearance:
- `css/dark.css` - Dark theme styles
- `css/light.css` - Light theme styles

## Security Features

- **Password Protection**: Optional gallery-wide password
- **File Type Validation**: Only processes allowed image formats
- **Directory Protection**: Prevents direct access to sensitive files
- **Session Management**: Secure login handling

## Mobile Support

- Responsive grid layout adapts to screen size
- Touch-friendly navigation
- Optimized image loading for mobile connections
- Retina display support with 2x thumbnail resolution

## URL Structure

Clean, SEO-friendly URLs:
- `/` - Gallery home (year index)
- `/2024` - Photos from 2024
- `/2024/January` - January 2024 photos
- `/2024/January/photo-name` - Individual photo view

## Troubleshooting

### Common Issues

**Images not displaying:**
- Check file permissions on `photos/` directory
- Ensure GD extension is installed: `php -m | grep -i gd`
- Run `php processor.php` to generate thumbnails

**HEIC files not processing:**
- Install ImageMagick: `sudo apt-get install php-imagick` or `pecl install imagick`
- Verify installation: `php -m | grep -i imagick`

**URLs not working:**
- Ensure `.htaccess` exists
- Ensure mod_rewrite is enabled on Apache
- Check web server configuration

**Memory issues with large images:**
- Increase PHP memory limit in `php.ini`: `memory_limit = 512M`
- Process images in smaller batches

## Requirements

### Minimum Requirements
- PHP 8.4+
- GD extension (usually included)
- Web server with URL rewriting support
- File system write permissions

### Recommended
- ImageMagick extension (for HEIC support)
- At least 512MB PHP memory limit
- SSD storage for better performance

## Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues for bugs and feature requests.

## License

This project is open source and licensed under AGPL-3.0 license.

## Support

If you encounter any issues or have questions:
1. Check the troubleshooting section above
2. Check your web server error logs
3. Open an issue on GitHub with details about your setup

---

**Image Gallery EXpress** - Simple, fast, and elegant photo galleries for everyone.

https://github.com/josephbu/igex.git

