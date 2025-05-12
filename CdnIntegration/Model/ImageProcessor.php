<?php
namespace MagoArab\CdnIntegration\Model;

class ImageProcessor
{
    /**
     * @var \MagoArab\CdnIntegration\Helper\Data
     */
    protected $helper;
    
    /**
     * @param \MagoArab\CdnIntegration\Helper\Data $helper
     */
    public function __construct(
        \MagoArab\CdnIntegration\Helper\Data $helper
    ) {
        $this->helper = $helper;
    }
    
/**
 * Convert image to WebP format with optimized compression
 *
 * @param string $sourcePath
 * @return string|bool Path to WebP image or false on failure
 */
public function convertToWebp($sourcePath)
{
    // Check if source file exists
    if (!file_exists($sourcePath)) {
        $this->helper->log("Source file does not exist: {$sourcePath}", 'error');
        return false;
    }
    
    // Get file extension and size
    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $originalSize = filesize($sourcePath);
    
    // Skip if already WebP
    if ($extension === 'webp') {
        return $sourcePath;
    }
    
    // Only convert jpg, jpeg, png
    if (!in_array($extension, ['jpg', 'jpeg', 'png'])) {
        return false;
    }
    
    // Create destination path
    $destinationPath = substr($sourcePath, 0, strrpos($sourcePath, '.')) . '.webp';
    
    // Use GD library to convert image
    try {
        // Check if GD is available
        if (!extension_loaded('gd')) {
            $this->helper->log("GD library is not available, cannot convert to WebP", 'error');
            return false;
        }
        
        // Create image resource based on type
        switch ($extension) {
            case 'jpg':
            case 'jpeg':
                $image = imagecreatefromjpeg($sourcePath);
                // For JPEG, we can use higher compression without noticeable quality loss
                $quality = 70;
                break;
            case 'png':
                $image = imagecreatefrompng($sourcePath);
                // Handle transparency
                imagepalettetotruecolor($image);
                imagealphablending($image, true);
                imagesavealpha($image, true);
                // For PNG, use lower compression to maintain quality
                $quality = 70;
                break;
            default:
                return false;
        }
        
        if (!$image) {
            $this->helper->log("Failed to create image resource from {$sourcePath}", 'error');
            return false;
        }
        
        // Progressive compression approach - try different quality levels
        $bestQuality = null;
        $bestSize = $originalSize;
        $bestTempPath = null;
        
        // Test different quality levels to find optimal compression
        $qualityLevels = [70, 60, 80, 50, 90];
        
        foreach ($qualityLevels as $testQuality) {
            $tempPath = $destinationPath . '.temp' . $testQuality;
            
            // Save as WebP with test quality
            $result = imagewebp($image, $tempPath, $testQuality);
            
            if ($result) {
                $tempSize = filesize($tempPath);
                
                // If this quality level gives better compression, remember it
                if ($tempSize < $bestSize) {
                    // Delete previous best if exists
                    if ($bestTempPath && file_exists($bestTempPath)) {
                        @unlink($bestTempPath);
                    }
                    
                    $bestQuality = $testQuality;
                    $bestSize = $tempSize;
                    $bestTempPath = $tempPath;
                } else {
                    // Clean up this temp file as it's not better
                    @unlink($tempPath);
                }
            }
        }
        
        // Clean up the image resource
        imagedestroy($image);
        
        // If no improvement was found, return false
        if ($bestSize >= $originalSize || !$bestTempPath) {
            $this->helper->log("WebP conversion didn't yield smaller file size for {$sourcePath}", 'info');
            
            // Clean up any remaining temp files
            foreach ($qualityLevels as $quality) {
                $tempPath = $destinationPath . '.temp' . $quality;
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }
            
            return false;
        }
        
        // Rename the best temp file to the final destination
        rename($bestTempPath, $destinationPath);
        
        // Clean up any other temp files
        foreach ($qualityLevels as $quality) {
            $tempPath = $destinationPath . '.temp' . $quality;
            if ($tempPath !== $bestTempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
        
        // Log success with compression stats
        $savings = round(($originalSize - $bestSize) / $originalSize * 100, 2);
        
        $this->helper->log(
            "Converted {$sourcePath} to WebP with quality {$bestQuality}. Size reduced from " . 
            $this->formatBytes($originalSize) . " to " . 
            $this->formatBytes($bestSize) . " ({$savings}% saved)",
            'info'
        );
        
        return $destinationPath;
    } catch (\Exception $e) {
        $this->helper->log("Failed to convert image to WebP: " . $e->getMessage(), 'error');
        return false;
    }
}
    
    /**
     * Check if file is an image
     *
     * @param string $filePath
     * @return bool
     */
    public function isImageFile($filePath)
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif']);
    }
    
    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}