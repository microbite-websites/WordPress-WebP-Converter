<?php
 
/**
 * Microbite WebP Converter 
 * 
 * Automatically converts uploaded images (JPEG, PNG, GIF, HEIC) to optimized WebP format.
 * This snippet is perfect for handling large image uploads - it intelligently resizes images
 * to your specified dimensions and converts them to WebP for maximum compression while
 * maintaining quality. Original files are replaced with optimized versions to save server space.
 * 
 * Features:
 * - Automatic WebP conversion with size comparison (only converts if WebP is smaller)
 * - Configurable max width/height with aspect ratio preservation  
 * - Adjustable compression quality (1-100)
 * - EXIF orientation correction (fixes rotated images)
 * - Replaces originals with optimized files (saves storage space)
 * - Admin settings panel in Media options
 * - Smart fallback (keeps original if WebP conversion fails or is larger)
 * 
 * Requirements: 
 * - WordPress 5.0+
 * - PHP Imagick extension (will show admin notice if missing)
 * 
 * Installation Options:
 * 1. Add to your theme's functions.php file
 * 2. Save as a must-use plugin (wp-content/mu-plugins/webp-converter.php)
 * 3. Use with code snippet plugins (WPCode, Code Snippets, Fluent Snippets, etc.)
 * 4. Add to your child theme's functions.php for theme updates safety
 * 
 * For a more professional solution with advanced features and support,
 * check out Microbite Image Converter: https://www.imageconverter.com.au/
 *
 * @version 1.03
 * @author Microbite (info@microbite.com.au)
 */

function mbwpc_admin_enqueue_styles() {
    wp_enqueue_style('mbwpc-admin-styles', plugin_dir_url(__FILE__) . 'css/mbwpc-styles.css', array(), '1.0.0', 'all');
}
add_action('admin_enqueue_scripts', 'mbwpc_admin_enqueue_styles');

add_action('init', 'mbwpc_plugin_init');

function mbwpc_plugin_init() {
    add_action('admin_notices', 'mbwpc_admin_notices');
    add_action('admin_init', 'mbwpc_register_settings');
    add_filter('wp_handle_upload', 'mbwpc_handle_upload_convert_to_webp');
}

/**
 * Display admin notices for Imagick extension and WebP conversion settings.
 */
function mbwpc_admin_notices() {
    $screen = get_current_screen();
    if ('upload' === $screen->id || 'media' === $screen->id) {
        if (!extension_loaded('imagick')) {
            echo "<div class='notice notice-warning is-dismissible'><p><strong>Warning:</strong> The Imagick PHP extension required for WebP conversion is not installed or enabled. Please install or enable Imagick.</p></div>";
        } else {
            $settings_url = admin_url('options-media.php');
            $webp_conversion_enabled = get_option('mbwpc_convert_to_webp', false);

            if ($webp_conversion_enabled) {
                echo "<div class='notice notice-info is-dismissible'><p><strong>Enabled:</strong> WebP image conversion is active. You can disable this feature on the <a href='" . esc_url($settings_url) . "'>Settings &gt; Media</a> page.</p></div>";
            } else {
                echo "<div class='notice notice-info is-dismissible'><p><strong>Disabled:</strong> WebP image conversion is not active. You can enable this feature on the <a href='" . esc_url($settings_url) . "'>Settings &gt; Media</a> page.</p></div>";
            }
        }
    }
}

/**
 * Register settings for WebP conversion and image processing.
 */
function mbwpc_register_settings() {
    register_setting('media', 'mbwpc_convert_to_webp', [
        'type' => 'boolean',
        'sanitize_callback' => 'rest_sanitize_boolean',
        'default' => false,
    ]);

    register_setting('media', 'mbwpc_max_width', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 1920,
    ]);

    register_setting('media', 'mbwpc_max_height', [
        'type' => 'integer',
        'sanitize_callback' => 'absint',
        'default' => 1080,
    ]);

    register_setting('media', 'mbwpc_compression_quality', [
        'type' => 'integer',
        'sanitize_callback' => function($value) {
            return max(1, min(100, absint($value)));
        },
        'default' => 80,
    ]);

    add_settings_field(
        'mbwpc_convert_to_webp',
        __('Convert Uploaded Images to WebP', 'mbwpc'),
        'mbwpc_field_callback',
        'media',
        'default',
        ['label_for' => 'mbwpc_convert_to_webp']
    );

    add_settings_field(
        'mbwpc_image_settings',
        __('Image Processing Settings', 'mbwpc'),
        'mbwpc_image_settings_callback',
        'media',
        'default'
    );
}

/**
 * Display the image processing settings fields.
 */
function mbwpc_image_settings_callback() {
    $maxWidth = get_option('mbwpc_max_width', 1920);
    $maxHeight = get_option('mbwpc_max_height', 1080);
    $compressionQuality = get_option('mbwpc_compression_quality', 80);

    echo '<fieldset>';
    echo '<p><label for="mbwpc_max_width">Max Width (pixels):</label><br>';
    echo '<input type="number" id="mbwpc_max_width" name="mbwpc_max_width" value="' . esc_attr($maxWidth) . '" min="1" max="9999" /></p>';
    
    echo '<p><label for="mbwpc_max_height">Max Height (pixels):</label><br>';
    echo '<input type="number" id="mbwpc_max_height" name="mbwpc_max_height" value="' . esc_attr($maxHeight) . '" min="1" max="9999" /></p>';
    
    echo '<p><label for="mbwpc_compression_quality">Compression Quality (1-100):</label><br>';
    echo '<input type="number" id="mbwpc_compression_quality" name="mbwpc_compression_quality" value="' . esc_attr($compressionQuality) . '" min="1" max="100" /></p>';
    echo '</fieldset>';
}

/**
 * Display the WebP conversion setting field.
 */
function mbwpc_field_callback() {
    $value = get_option('mbwpc_convert_to_webp', false);
    echo '<input type="checkbox" id="mbwpc_convert_to_webp" name="mbwpc_convert_to_webp" ' . checked(1, $value, false) . ' value="1">';
    echo '<label for="mbwpc_convert_to_webp">' . esc_html__('Enable automatic conversion of uploaded images to WebP format.', 'mbwpc') . '</label>';
}

/**
 * Handle the image upload and convert to WebP format.
 *
 * @param array $upload Array containing the upload data.
 * @return array Modified upload data.
 */
function mbwpc_handle_upload_convert_to_webp($upload) {
    if (!get_option('mbwpc_convert_to_webp')) {
        return $upload; // Skip the conversion if not enabled
    }

    $maxWidth = get_option('mbwpc_max_width', 1920);
    $maxHeight = get_option('mbwpc_max_height', 1080);
    $compressionQuality = get_option('mbwpc_compression_quality', 80);

    $valid_types = ['image/jpeg', 'image/png', 'image/gif', 'image/heic'];

    if (in_array($upload['type'], $valid_types)) {
        $file_path = $upload['file'];

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($file_path);

                // Adjust orientation based on EXIF data
                switch ($imagick->getImageOrientation()) {
                    case Imagick::ORIENTATION_BOTTOMRIGHT:
                        $imagick->rotateImage("#000", 180);
                        break;
                    case Imagick::ORIENTATION_RIGHTTOP:
                        $imagick->rotateImage("#000", 90);
                        break;
                    case Imagick::ORIENTATION_LEFTBOTTOM:
                        $imagick->rotateImage("#000", -90);
                        break;
                }

                $imagick->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

                $originalWidth = $imagick->getImageWidth();
                $originalHeight = $imagick->getImageHeight();
                $aspectRatio = $originalWidth / $originalHeight;
                $newWidth = $originalWidth;
                $newHeight = $originalHeight;

                if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                    if ($newWidth > $maxWidth) {
                        $newWidth = $maxWidth;
                        $newHeight = $newWidth / $aspectRatio;
                    }
                    if ($newHeight > $maxHeight) {
                        $newHeight = $maxHeight;
                        $newWidth = $newHeight * $aspectRatio;
                    }
                }

                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
                $imagick->setImageFormat('webp');
                $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality($compressionQuality);

                $file_info = pathinfo($file_path);
                $dirname = $file_info['dirname'];
                $filename = $file_info['filename'];
                $new_file_path = $dirname . '/' . $filename . '.webp';
                $imagick->writeImage($new_file_path);

                // Compare file sizes
                $original_size = filesize($file_path);
                $new_size = filesize($new_file_path);

                if ($new_size < $original_size) {
                    if (file_exists($new_file_path)) {
                        $upload['file'] = $new_file_path;
                        $upload['url'] = str_replace(basename($upload['url']), basename($new_file_path), $upload['url']);
                        $upload['type'] = 'image/webp';
                        @unlink($file_path);
                    }
                } else {
                    @unlink($new_file_path);
                }

                $imagick->clear();
                $imagick->destroy();

            } catch (Exception $e) {
                // Log error or handle gracefully
                error_log('WebP conversion failed: ' . $e->getMessage());
            }
        } else {
            // Only show this message to admins in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>Imagick is not installed. WebP conversion cannot be performed.</p></div>';
                });
            }
        }
    }

    return $upload;
}
?>