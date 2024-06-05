#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use Aws\S3\S3Client;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

class SFTPToS3Transfer
{
    private $config;
    private $s3FileSystem;
    private $sftpFileSystem;
    public function __construct($config_filename)
    {
        // Load configuration from file 
        $this->config = json_decode(file_get_contents($config_filename), true);
        if (false === $this->config) {
            throw new Error("Unable to read config $config_filename");
        }
        // Initialize S3 client
        $s3Client = new S3Client($this->config['s3']);
        // The internal adapter
        $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter(
            // S3Client
            $s3Client,
            // Bucket name
            $this->config['s3']['bucket']
        );
        // Initialize Flysystem filesystem for S3
        $this->s3FileSystem = new Filesystem($adapter);
    }

    /**
     * Read some of the file content to extract tags/data
     */
    private function getMetadata($meta_tag,$localFilePath) {
        //Check content to extract some meta-data
        $fp=fopen($localFilePath,'r');
        if($fp) {        
            $keys = ['ISA01','ISA02','ISA03','ISA04','ISA05','ISA06','ISA07','ISA08','ISA09','ISA10','ISA11','ISA12','ISA13','ISA14','ISA15','ISA16',
                     'GS01','GS02','GS03','GS04','GS05','GS06','GS07','GS08',
                     'ST01','ST02'];
            if(in_array($meta_tag,$keys)) {
                $hdr = fread($fp,256);
                $sep = $hdr[3];
                $del = $hdr[105];
                $segments =explode($del,$hdr);
                $metadata=[];
                foreach($segments as $k=>$line) {
                    $elements = explode($sep,$line);
                    $elements = array_map('trim',$elements); 
                    for($id=1;$id<count($elements);$id++) {
                        $ele_id = sprintf('%s%02d',$elements[0],$id);
                        $metadata[$ele_id]=$elements[$id];
                    }
                }
                return $metadata[$meta_tag];
            }else{
                $hdr = fread($fp,2048);
                $matches=[];
                if(preg_match("|<$meta_tag>([^<]*)</$meta_tag>|m",$hdr,$matches)) {
                    return $matches[1];
                }
            }
        }
        return false;
    }

    /**
     * The pattern will be an expression that combines literal characters and tokens enclosed in '%' in the form: "xxxx%token%yyyy"
     * Tokens will be evaluated with other functions that will take as input the token parameters, the filename and return an string 
     * that will be replaced in place of the token. If a literal '%' then double escape sequence should be used, like: '%%'
     * The function should a new filename based on the pattern provided.
     * @param string $pattern A pattern that accoun
     * @param string $sourceFilename The filename downloaded
     * @param string $localFilePath The local file path of the file downloaded
     * @return string New filename
     */
    private function renameFile(?string $pattern, string $sourceFilename, string $localFilePath)
    {
        if($pattern === null) {
            return $sourceFilename;
        }

        $replaceToken = function (string $token) use ($sourceFilename, $localFilePath): string {
            switch ($token) {
                case 'filename':
                    return pathinfo($sourceFilename, PATHINFO_FILENAME);
                case 'extension':
                    return pathinfo($sourceFilename, PATHINFO_EXTENSION);
                case 'date':
                    return date('Ymd');
                case 'timestamp':
                    return time();
                case 'time':
                    return date('His');
                default:
                    if(preg_match('/meta\[([^\]]+)\]/',$token,$var)){
                        $value = $this->getMetadata($var[1],$localFilePath);
                        if($value !== false) {
                            return "$value";
                        }
                    }
                    // If the token is not recognized, keep it as is
                    return "$token";
            }
        };

        // Replace tokens in the pattern with their corresponding values
        $newFilename = preg_replace_callback('/%([^%]+)%/', function ($matches) use ($replaceToken) {
            return $replaceToken($matches[1]);
        }, $pattern);

        // Double escape any remaining '%' characters
        $newFilename = str_replace('%', '%%', $newFilename);

        return $newFilename;
    }

    // Define function to download and upload files
    function transferFiles($sourceFSystem,$destinationFSystem,$profileConfig)
    {

        $sourcePath = $profileConfig['source_path'];
        $destinationPath = $profileConfig['destination_path'];
        $wildcardFilters = $profileConfig['wildcard_filters'] ?? ['*.*'];
        $renamePattern = $profileConfig['rename_pattern'] ?? null;
        $searchReplace = $profileConfig['search_replace_patterns'] ?? [];
        $dispose = $profileConfig['disposition'] ?? 1;
        $mode = $profileConfig['mode'] ?? 'pull';
        $downloadedFiles = [];
        $uploadedFiles = [];

        // List files in source directory
        $files = $sourceFSystem->listContents($sourcePath);


        foreach ($files as $file) {
            // Check if file matches filter
            foreach ($wildcardFilters as $filter) {
                $file_basename=basename($file['path']);
                if ($file['type']=='file' && fnmatch($filter,$file_basename)) {
                    $tries=0;
                    $bytes=0;
                    while($file['fileSize']!==$bytes && $tries++ < 3 ) {
                        // Download file
                        $stream = $sourceFSystem->readStream($file['path']);
                        $localFilePath = tempnam(sys_get_temp_dir(), 'sftp_download');
                        file_put_contents($localFilePath, stream_get_contents($stream));
                        fclose($stream);
                        $bytes = filesize($localFilePath);
                    }
                    if($bytes !== $file['fileSize']) {
                        $this->logMessage("Unable to download completely the file: $file_basename expected {$file['fileSize']} bytes, $bytes downloaded");
                        continue;
                    }
                    $this->logMessage("Downloaded file: $file_basename of " . filesize($localFilePath) . " bytes");
                    $downloadedFiles[$file_basename] = $bytes;

                    // Read contents of the downloaded file
                    $fileContents = file_get_contents($localFilePath);

                    // Perform search and replace based on user-defined patterns
                    foreach ($searchReplace as $pattern => $replacement) {
                        $fileContents = str_replace($pattern, $replacement, $fileContents);
                    }

                    // Write modified contents to a new temporary file
                    $modifiedLocalFilePath = tempnam(sys_get_temp_dir(), 'modified');
                    file_put_contents($modifiedLocalFilePath, $fileContents);
                    // Rename file if needed                    
                    $newFileName = $this->renameFile($renamePattern, $file_basename, $modifiedLocalFilePath);

                    // Upload file to S3
                    $destinationFSystem->writeStream($destinationPath . '/' . $newFileName, fopen($modifiedLocalFilePath, 'r'));
                    $this->logMessage("Uploaded files: $newFileName of " . filesize($localFilePath) . " bytes");
                    $uploadedFiles[$file_basename] = $newFileName;
                    $this->auditTransfer($mode, $file['path'], $destinationPath . '/' . $newFileName, $bytes);
                    // Remove local temporary file
                    unlink($localFilePath);
                    unlink($modifiedLocalFilePath);
                    // Dispose downloaded file
                    switch($dispose) {
                        case 1:
                            $this->logMessage("Removing {$file['path']} from server");
                            $sourceFSystem->delete($file['path']);
                            break;
                        case 2:
                            $this->logMessage("Archiving {$file['path']} on server");
                            // $sourceFSystem->move($file['path'],$dispose_path);
                            break;
                    }
                }
            }
        }

        // Log transfer details
        $this->logMessage("Downloaded files: " . json_encode($downloadedFiles));
        $this->logMessage("Uploaded files: " . json_encode($uploadedFiles));
    }

    // Define function to run transfer for each profile
    private function runTransfer($profileConfig)
    {
        $provider = new SftpConnectionProvider(
            $profileConfig['sftp']['host'], // host (required)
            $profileConfig['sftp']['username'], // username (required)
            $profileConfig['sftp']['password'] ?? null, // password (optional, default: null) set to null if privateKey is used
            $profileConfig['sftp']['private_key_path'] ?? null, // private key (optional, default: null) can be used instead of password, set to null if password is set
            $profileConfig['sftp']['private_key_passphrase'] ?? null, // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
            $profileConfig['sftp']['port'] ?? 22, // port (optional, default: 22)
            false, // use agent (optional, default: false)
            30, // timeout (optional, default: 10)
            10, // max tries (optional, default: 4)
            $profileConfig['sftp']['host_fingerprint'] ?? null, // host fingerprint (optional, default: null),
            null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
        );
        $permissions = PortableVisibilityConverter::fromArray([
            'file' => [
                'public' => 0640,
                'private' => 0604,
            ],
            'dir' => [
                'public' => 0740,
                'private' => 7604,
            ],
        ]);
        // Initialize SFTP adapter
        $sftpAdapter = new SftpAdapter(
            $provider,
            $profileConfig['sftp']['root'] ?? '/', // root path (required)
            $permissions
        );


        // Initialize Flysystem filesystem for SFTP
        $this->sftpFileSystem = new Filesystem($sftpAdapter);

        $transferMode = $profileConfig['mode'] ?? 'pull';
        if( $transferMode == 'pull') {
            $this->transferFiles($this->sftpFileSystem,$this->s3FileSystem, $profileConfig);
        }else if($transferMode =='push') {
            $this->transferFiles($this->s3FileSystem,$this->sftpFileSystem, $profileConfig);
        }

        
    }
    public function execute()
    {
        // Run transfers for each profile
        foreach ($this->config['profiles'] as $profile => $profileConfig) {
            if ($profile != 's3' && $profile != 'sftp') {
                try {
                    $this->logMessage("Executing transfer for $profile");
                    $this->runTransfer($profileConfig);
                } catch (Throwable $t) {
                    $this->logMessage("[ERROR] " . $t->getMessage());
                }
            }
        }
    }
    private function auditTransfer($mode, $source, $target, $bytes)
    {
        if($mode == 'pull') {
            $formatted_message = sprintf('[%s]: Transfer sftp://%s -> S3://%s (%d bytes) ' . PHP_EOL, date('Y-m-d H:i:s'), $source, $target, $bytes);
        }else{
            $formatted_message = sprintf('[%s]: Transfer S3://%s -> sftp://%s (%d bytes) ' . PHP_EOL, date('Y-m-d H:i:s'), $source, $target, $bytes);
        }
        file_put_contents($this->config['audit_log'], $formatted_message, FILE_APPEND);
    }
    private function logMessage($message)
    {
        $formatted_message = sprintf('[%s]: %s' . PHP_EOL, date('Y-m-d H:i:s'), $message);
        file_put_contents($this->config['transfer_log'], $formatted_message, FILE_APPEND);
        error_log($formatted_message);
    }
}

try {
    $configFile = __FILE__ . '.config.json';
    if ($argc > 1) {
        $configFile = $argv[1];
    }
    $obj = new SFTPToS3Transfer($configFile);
    $obj->execute();
} catch (Throwable $t) {
    error_log("[FATAL] " . $t->getMessage());
}
