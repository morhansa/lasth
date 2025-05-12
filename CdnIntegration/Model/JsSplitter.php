<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class JsSplitter
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var FileDriver
     */
    protected $fileDriver;
    
    /**
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     */
    public function __construct(
        Helper $helper,
        GithubApi $githubApi,
        Filesystem $filesystem,
        FileDriver $fileDriver
    ) {
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }
    
/**
 * Check if a JS file should be split based on size or criticality
 *
 * @param string $localPath
 * @return bool
 */
public function shouldSplitFile($localPath)
{
    if (!$this->helper->isJsOptimizationEnabled()) {
        return false;
    }
    
    if (!file_exists($localPath)) {
        return false;
    }
    
    // Check file extension
    $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
    if ($extension !== 'js') {
        return false;
    }
    
    // Check if this is a critical file that needs special handling
    if ($this->helper->isOptimizeCriticalJsEnabled() && $this->helper->isCriticalJsFile($localPath)) {
        $this->helper->log("Critical JS file detected: {$localPath}", 'info');
        return true;
    }
    
    // Check file size for regular files
    if ($this->helper->isSplitLargeJsEnabled()) {
        $fileSize = filesize($localPath);
        $thresholdKb = (int)$this->helper->getSplitJsThreshold();
        $thresholdBytes = $thresholdKb * 1024;
        
        $this->helper->log("Checking file size for {$localPath}: {$fileSize} bytes (threshold: {$thresholdBytes} bytes)", 'debug');
        
        return ($fileSize > $thresholdBytes);
    }
    
    return false;
}
/**
 * Create a specialized loader for RequireJS itself
 *
 * @param string $chunkBaseName
 * @param int $chunks
 * @param string $remoteDir
 * @return string
 */
private function createRequireJsOptimizedLoader($chunkBaseName, $chunks, $remoteDir)
{
    $loaderContent = "// Optimized RequireJS Loader\n";
    $loaderContent .= "// This is a special optimized loader for RequireJS itself\n";
    $loaderContent .= "(function() {\n";
    $loaderContent .= "    // Store original load time for performance tracking\n";
    $loaderContent .= "    window._requireJsLoadStart = new Date().getTime();\n\n";
    
    $loaderContent .= "    // Chunks for RequireJS\n";
    $loaderContent .= "    var chunks = [];\n";
    
    for ($i = 1; $i <= $chunks; $i++) {
        $chunkPath = $remoteDir . $chunkBaseName . $i . '.js';
        $loaderContent .= "    chunks.push('" . $chunkPath . "');\n";
    }
    
    $loaderContent .= "\n";
    $loaderContent .= "    // Create the worker for parallel processing\n";
    $loaderContent .= "    var useWorker = (typeof Worker !== 'undefined' && typeof Blob !== 'undefined' && typeof URL !== 'undefined');\n";
    $loaderContent .= "    var worker = null;\n";
    $loaderContent .= "    var processingQueue = [];\n";
    $loaderContent .= "    var loadedChunks = 0;\n";
    $loaderContent .= "    var chunkContents = [];\n\n";
    
    $loaderContent .= "    // Pre-fetch all chunks immediately to improve performance\n";
    $loaderContent .= "    chunks.forEach(function(chunk, index) {\n";
    $loaderContent .= "        fetch(chunk)\n";
    $loaderContent .= "            .then(function(response) { return response.text(); })\n";
    $loaderContent .= "            .then(function(code) {\n";
    $loaderContent .= "                chunkContents[index] = code;\n";
    $loaderContent .= "                loadedChunks++;\n";
    $loaderContent .= "                if (loadedChunks === chunks.length) {\n";
    $loaderContent .= "                    executeAllChunks();\n";
    $loaderContent .= "                }\n";
    $loaderContent .= "            });\n";
    $loaderContent .= "    });\n\n";
    
    $loaderContent .= "    function executeAllChunks() {\n";
    $loaderContent .= "        // Execute all chunks in sequence\n";
    $loaderContent .= "        try {\n";
    $loaderContent .= "            // Combine all chunks into one script\n";
    $loaderContent .= "            var fullScript = chunkContents.join('\\n');\n";
    $loaderContent .= "            // Execute the combined script\n";
    $loaderContent .= "            var scriptElement = document.createElement('script');\n";
    $loaderContent .= "            scriptElement.textContent = fullScript;\n";
    $loaderContent .= "            document.head.appendChild(scriptElement);\n";
    $loaderContent .= "            \n";
    $loaderContent .= "            // Log performance\n";
    $loaderContent .= "            var totalTime = new Date().getTime() - window._requireJsLoadStart;\n";
    $loaderContent .= "            console.log('RequireJS optimized loader: loaded and executed in ' + totalTime + 'ms');\n";
    $loaderContent .= "        } catch (e) {\n";
    $loaderContent .= "            console.error('Error executing RequireJS chunks:', e);\n";
    $loaderContent .= "            // Fallback to traditional loading\n";
    $loaderContent .= "            loadFallback();\n";
    $loaderContent .= "        }\n";
    $loaderContent .= "    }\n\n";
    
    $loaderContent .= "    function loadFallback() {\n";
    $loaderContent .= "        console.log('Falling back to traditional loading for RequireJS');\n";
    $loaderContent .= "        var script = document.createElement('script');\n";
    $loaderContent .= "        script.src = chunks[0]; // Use first chunk as fallback\n";
    $loaderContent .= "        document.head.appendChild(script);\n";
    $loaderContent .= "    }\n";
    
    $loaderContent .= "})();\n";
    
    return $loaderContent;
}
    
    /**
     * Split a large JS file into multiple smaller files
     *
     * @param string $localPath Original file path
     * @param string $remotePath Remote path on GitHub
     * @return array|bool Array of split file URLs or false on failure
     */
    public function splitAndUploadFile($localPath, $remotePath)
    {
        $this->helper->log("Starting to split large JS file: {$localPath}", 'info');
        
        try {
            // Read the file content
            $content = $this->fileDriver->fileGetContents($localPath);
            if (empty($content)) {
                $this->helper->log("File is empty: {$localPath}", 'error');
                return false;
            }
            
            // Determine number of chunks
            $chunks = (int)$this->helper->getSplitJsChunks();
            if ($chunks < 2) {
                $chunks = 5; // Default to 5 chunks
            }
            
            // Calculate chunk size (approximately)
            $totalSize = strlen($content);
            $chunkSize = ceil($totalSize / $chunks);
            
            $this->helper->log("Splitting file of size {$totalSize} bytes into {$chunks} chunks (approx. {$chunkSize} bytes each)", 'info');
            
            // Check if file contains "define" or "require" - important for RequireJS
            $hasRequireJs = (strpos($content, 'define(') !== false || strpos($content, 'require(') !== false);
            
            // Create a temporary directory for chunks
            $tempDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP)->getAbsolutePath();
            $uniqueDir = 'js_split_' . uniqid();
            $chunkDir = $tempDir . $uniqueDir . '/';
            
            if (!file_exists($chunkDir)) {
                mkdir($chunkDir, 0777, true);
            }
            
            // Generate remote path prefix (without extension)
            $remotePathInfo = pathinfo($remotePath);
            $remoteDir = !empty($remotePathInfo['dirname']) ? $remotePathInfo['dirname'] . '/' : '';
            $remoteFilename = $remotePathInfo['filename'];
            $chunkBaseName = $remoteFilename . '_chunk';
            
            // Split the file and upload each chunk
            $chunkFiles = [];
            $chunkUrls = [];
            
            // Create a loader script that will load all chunks
            $loaderContent = "// JavaScript file loader for split chunks\n";
            $loaderContent .= "// Original file: {$remotePath}\n";
            $loaderContent .= "// Split into {$chunks} chunks\n\n";
            
// Check if this is RequireJS itself and needs special handling
$isRequireJsItself = $this->helper->isCriticalJsFile($localPath);

if ($isRequireJsItself) {
    // Special loader for RequireJS itself
    $loaderContent .= $this->createRequireJsOptimizedLoader($chunkBaseName, $chunks, $remoteDir);
} else if ($hasRequireJs) {
    // Special handling for other RequireJS modules
    $loaderContent .= $this->createRequireJsLoader($chunkBaseName, $chunks, $remoteDir);
} else {
    // Standard JS loader
    $loaderContent .= $this->createStandardJsLoader($chunkBaseName, $chunks, $remoteDir);
}
            
            // Save loader script
            $loaderPath = $chunkDir . $remoteFilename . '_loader.js';
            file_put_contents($loaderPath, $loaderContent);
            
            // Create and upload the loader
            $loaderRemotePath = $remoteDir . $remoteFilename . '_loader.js';
            $loaderSuccess = $this->githubApi->uploadFile($loaderPath, $loaderRemotePath);
            
            if (!$loaderSuccess) {
                $this->helper->log("Failed to upload loader script", 'error');
                return false;
            }
            
            $chunkUrls[] = $loaderRemotePath;
            
            // Split content into chunks and upload
            for ($i = 0; $i < $chunks; $i++) {
                $start = $i * $chunkSize;
                $chunkContent = substr($content, $start, $chunkSize);
                
                // Save chunk to temp file
                $chunkFileName = $chunkBaseName . ($i + 1) . '.js';
                $chunkFilePath = $chunkDir . $chunkFileName;
                file_put_contents($chunkFilePath, $chunkContent);
                
                // Upload chunk to GitHub
                $chunkRemotePath = $remoteDir . $chunkFileName;
                $success = $this->githubApi->uploadFile($chunkFilePath, $chunkRemotePath);
                
                if ($success) {
                    $chunkFiles[] = $chunkFilePath;
                    $chunkUrls[] = $chunkRemotePath;
                    $this->helper->log("Successfully uploaded chunk " . ($i+1) . ": {$chunkRemotePath}", 'info');
                } else {
                    $this->helper->log("Failed to upload chunk " . ($i+1), 'error');
                    // Clean up
                    foreach ($chunkFiles as $file) {
                        @unlink($file);
                    }
                    $this->removeDirectory($chunkDir);
                    return false;
                }
            }
            
            // Clean up temp files
            foreach ($chunkFiles as $file) {
                @unlink($file);
            }
            @unlink($loaderPath);
            $this->removeDirectory($chunkDir);
            
            $this->helper->log("Successfully split and uploaded JS file into {$chunks} chunks", 'info');
            
            return [
                'loader' => $loaderRemotePath,
                'chunks' => $chunkUrls
            ];
            
        } catch (\Exception $e) {
            $this->helper->log("Error splitting JS file: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Create a loader script for RequireJS files
     *
     * @param string $chunkBaseName
     * @param int $chunks
     * @param string $remoteDir
     * @return string
     */
    private function createRequireJsLoader($chunkBaseName, $chunks, $remoteDir)
    {
        $loaderContent = "// RequireJS Loader\n";
        
        // For RequireJS, use define.amd to detect if RequireJS is available
        $loaderContent .= "if (typeof define === 'function' && define.amd) {\n";
        $loaderContent .= "    // Load chunks through RequireJS\n";
        $loaderContent .= "    var chunkPaths = [];\n";
        
        for ($i = 1; $i <= $chunks; $i++) {
            $chunkPath = $remoteDir . $chunkBaseName . $i . '.js';
            $loaderContent .= "    chunkPaths.push('" . $chunkPath . "');\n";
        }
        
        $loaderContent .= "\n    // Load all chunks in sequence\n";
        $loaderContent .= "    define(['require'], function(require) {\n";
        $loaderContent .= "        var loadChunk = function(index) {\n";
        $loaderContent .= "            if (index >= chunkPaths.length) return;\n";
        $loaderContent .= "            console.log('Loading JS chunk ' + (index + 1) + ' of ' + chunkPaths.length);\n";
        $loaderContent .= "            require([chunkPaths[index]], function() {\n";
        $loaderContent .= "                setTimeout(function() { loadChunk(index + 1); }, 10);\n";
        $loaderContent .= "            });\n";
        $loaderContent .= "        };\n";
        $loaderContent .= "        loadChunk(0);\n";
        $loaderContent .= "    });\n";
        $loaderContent .= "} else {\n";
        
        // Fallback for non-RequireJS environments
        $loaderContent .= $this->createStandardJsLoader($chunkBaseName, $chunks, $remoteDir, 4);
        
        $loaderContent .= "}\n";
        
        return $loaderContent;
    }
    
    /**
     * Create a standard JS loader
     *
     * @param string $chunkBaseName
     * @param int $chunks
     * @param string $remoteDir
     * @param int $indent Number of spaces for indentation
     * @return string
     */
    private function createStandardJsLoader($chunkBaseName, $chunks, $remoteDir, $indent = 0)
    {
        $indentation = str_repeat(' ', $indent);
        $loaderContent = $indentation . "// Standard JS Loader\n";
        $loaderContent .= $indentation . "(function() {\n";
        $loaderContent .= $indentation . "    var chunks = [];\n";
        
        for ($i = 1; $i <= $chunks; $i++) {
            $chunkPath = $remoteDir . $chunkBaseName . $i . '.js';
            $loaderContent .= $indentation . "    chunks.push('" . $chunkPath . "');\n";
        }
        
        $loaderContent .= "\n" . $indentation . "    var loadedChunks = 0;\n";
        $loaderContent .= $indentation . "    var loadChunk = function(index) {\n";
        $loaderContent .= $indentation . "        if (index >= chunks.length) return;\n";
        $loaderContent .= $indentation . "        console.log('Loading JS chunk ' + (index + 1) + ' of ' + chunks.length);\n";
        $loaderContent .= $indentation . "        var script = document.createElement('script');\n";
        $loaderContent .= $indentation . "        script.src = chunks[index];\n";
        $loaderContent .= $indentation . "        script.onload = function() {\n";
        $loaderContent .= $indentation . "            loadedChunks++;\n";
        $loaderContent .= $indentation . "            if (loadedChunks < chunks.length) {\n";
        $loaderContent .= $indentation . "                setTimeout(function() { loadChunk(index + 1); }, 10);\n";
        $loaderContent .= $indentation . "            }\n";
        $loaderContent .= $indentation . "        };\n";
        $loaderContent .= $indentation . "        document.head.appendChild(script);\n";
        $loaderContent .= $indentation . "    };\n";
        $loaderContent .= $indentation . "    loadChunk(0);\n";
        $loaderContent .= $indentation . "})();\n";
        
        return $loaderContent;
    }
    
    /**
     * Recursively remove a directory
     *
     * @param string $dir
     * @return bool
     */
    private function removeDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->removeDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
}