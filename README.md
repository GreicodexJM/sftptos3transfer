# SFTP to S3 Transfer Script Documentation

## Overview

This script facilitates the transfer of files from an SFTP server to an AWS S3 bucket using PHP. It is designed to support multiple transfer profiles, allowing for flexible configuration and automation of file transfers.

### Key Features:
- Transfer files from SFTP to S3.
- Supports renaming and content manipulation of files.
- Logs all operations and maintains an audit log.
- Configurable through a JSON file.

## Requirements

- PHP 7.4 or higher
- Composer
- AWS SDK for PHP
- Flysystem and related adapters

## Installation

1. **Install Composer dependencies:**
   ```bash
   composer require aws/aws-sdk-php league/flysystem league/flysystem-sftp-v3 league/flysystem-aws-s3-v3
   ```

2. **Save the provided script (`transfer.php`) and configuration file (`config.json`) in your project directory.**

## Configuration

Create a configuration file `config.json` in the same directory as the script. This JSON file should include all necessary settings for the S3 and SFTP connections, logging, and profiles. Here is an example configuration:

```json
{
    "s3": {
        "bucket": "your_s3_bucket",
        "credentials": {
            "key": "S3_KEY",
            "secret": "S3_SECRET"
        },
        "region": "us-west-2",
        "version": "latest"
    },
    "audit_log": "/var/log/audit.log",
    "transfer_log": "/var/log/transfer.log",
    "profiles": {
        "server_1": {
            "sftp": {
                "host": "HOSTNAME",
                "port": 22,
                "username": "USER",
                "password": "SECRET",
                "root": "ROOT"
            },
            "source_path": "",
            "destination_path": "",
            "wildcard_filters": ["*.*"]
        },
        "server_2": {
            "sftp": {
                "host": "HOSTNAME",
                "port": 22,
                "username": "USER",
                "private_key_path": "/path/to/privatekey",
                "private_key_passphrase": "your-passphrase",
                "root": "ROOT"
            },
            "source_path": "",
            "destination_path": "",
            "search_replace_patterns": {
                "/pattern1/": "replacement1",
                "/pattern2/": "replacement2"
            },
            "wildcard_filters": [
                "*.txt",
                "*.csv"
            ]
        }
    }
}
```

## Script Usage

### Running the Script

To run the script, use the following command:

```bash
php transfer.php [optional-config-file]
```

If no configuration file is specified, the script will look for `transfer.php.config.json` by default.

## Script Breakdown

### Class `SFTPToS3Transfer`

- **Properties:**
  - `config`: Configuration loaded from the JSON file.
  - `s3FileSystem`: Flysystem filesystem instance for S3.
  - `sftpFileSystem`: Flysystem filesystem instance for SFTP.

- **Methods:**
  - `__construct($config_filename)`: Initializes the configuration and S3 filesystem.
  - `renameFile($pattern, $sourceFilename, $localFilePath)`: Renames files based on a given pattern.
  - `transferFiles($sourceFSystem, $destinationFSystem, $profileConfig)`: Handles the file transfer process.
  - `runTransfer($profileConfig)`: Manages the transfer process for each profile.
  - `execute()`: Executes transfers for all profiles.
  - `auditTransfer($source, $target, $bytes)`: Logs transfer details.
  - `logMessage($message)`: Logs messages to the specified log files.

### Functions

- **`renameFile(?string $pattern, string $sourceFilename, string $localFilePath)`**:
  - Renames a file based on the given pattern, which can include tokens like `%filename%`, `%date%`, etc.
  - Extracts metadata from the file content to support dynamic renaming.

- **`transferFiles($sourceFSystem, $destinationFSystem, $profileConfig)`**:
  - Downloads files from the source filesystem (SFTP).
  - Performs search and replace operations on the file content.
  - Renames the file if a pattern is provided.
  - Uploads the file to the destination filesystem (S3).
  - Handles file disposition (delete or archive) after transfer.

- **`runTransfer($profileConfig)`**:
  - Initializes the SFTP filesystem.
  - Calls `transferFiles` in either "pull" or "push" mode based on the profile configuration.

- **`execute()`**:
  - Iterates through each profile in the configuration and initiates the transfer process.

- **`auditTransfer($source, $target, $bytes)`**:
  - Logs detailed information about each file transfer.

- **`logMessage($message)`**:
  - Logs messages to both the transfer log and the audit log.

## Example Configuration File

Here is a detailed example of `config.json`:

```json
{
    "s3": {
        "bucket": "my_bucket",
        "credentials": {
            "key": "my_access_key",
            "secret": "my_secret_key"
        },
        "region": "us-west-2",
        "version": "latest"
    },
    "audit_log": "/var/log/audit.log",
    "transfer_log": "/var/log/transfer.log",
    "profiles": {
        "example_profile": {
            "sftp": {
                "host": "sftp.example.com",
                "port": 22,
                "username": "username",
                "password": "password",
                "root": "/"
            },
            "source_path": "/source/path",
            "destination_path": "/destination/path",
            "wildcard_filters": ["*.txt"],
            "rename_pattern": "%filename%_%date%.%extension%",
            "search_replace_patterns": {
                "foo": "bar",
                "hello": "world"
            }
        }
    }
}
```

## Logging and Audit

- **Transfer Log:** Records the operations performed by the script, such as downloading and uploading files.
- **Audit Log:** Provides a detailed record of each file transfer, including source, destination, and byte size.

Ensure the specified log files (`audit_log` and `transfer_log`) have appropriate write permissions.

## Error Handling

The script includes basic error handling:
- Logs errors to the specified log files.
- Uses try-catch blocks to capture and log exceptions during the transfer process.

## Conclusion

This script offers a robust solution for automating file transfers from an SFTP server to an AWS S3 bucket. With its flexible configuration and logging capabilities, it is well-suited for environments where such transfers are required regularly.