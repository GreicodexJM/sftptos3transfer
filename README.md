# SFTP to S3 Transfer Script Documentation

## Overview

This script facilitates the transfer of files from an SFTP server to an AWS S3 bucket using PHP. It is designed to support multiple transfer profiles, allowing for flexible configuration and automation of file transfers.

### Key Features:
- Transfer files from SFTP to S3 and back.
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

Create a configuration file `config.json` in the same directory as the script. This JSON file should include all necessary settings for the S3 and SFTP connections, logging, and profiles. 

### Configuration Parameters

* S3: This includes the AWS bucket and credentials needed to read / store the SFTP files exchanged.
* audit_log & transfer_log: Local filepaths to store the corresponding logs. These logs are append only, make sure to rotate them with a tool like "logrotate" on linux to avoid them filling up the disk.
* Profiles: Each SFTP connection is organized in profiles. Each profile can be arbitrarily name as long as it follows a JSON Variable name convention.
  * mode: Either `pull` or `push`. If set to `pull` the files from the SFTP will be downloaded to the AWS S3 folder. If set to `push` the files from the AWS S3 bucket will be uploaded into the SFTP server.
  * sftp: Connection server and credentials, both username/password and username/private_key is supported.
  * source_path: The SFTP or AWS_S3 path from which the files should be read from.
  * destination_path: The SFTP or AWS_S3 path from which the files should be written to.
  * wildcard_filters: Affects the `source_path`, only the files matching one of the filters will be considered for uploading.
  * search_replace_patterns: Affects the output, the file is modified before uploading with a simple text search and replace.
  * rename_pattern: Changes the filename written into `destination_path` based on a string pattern with replacement tokens. The pattern can be used to create a unique syntax on filenames so files can be differentiated. The accepted tokens are:
    * %filename%: base filename without extension
    * %extension%: filename extension
    * %date%: current date of the transfer in 20XX-XX-XX format
    * %time%: current time of the transfer in HH:MM:SS format
    * %meta[###]%: Internal data values for EDI files only ISA01..ISA16 and GS01..GS08 are supported. For XML only `ShipToCode` tags are supported   

Here is an example configuration:

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
            "mode": "pull",
            "sftp": {
                "host": "HOSTNAME",
                "port": 22,
                "username": "USER",
                "password": "SECRET",
                "root": "ROOT"
            },
            "source_path": "",
            "destination_path": "",
            "wildcard_filters": ["*.*"],
            "rename_pattern": "prod-%date%-%time%.%meta[GS01]%.%extension%"
        },
        "server_2": {
            "mode": "push",
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
            ],
            "rename_pattern": "prod-%date%-%time%.%meta[ISA03]%-%meta[ISA04]%.%extension%"
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

If no configuration file is specified, the script will look for `transfer.php.config.json` by default. Files are deleted after transfer.

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
