<?php

namespace App\Services\Helpers;

use Exception;
use App\Services\Enums\LogsEnum;
use Illuminate\Support\Facades\Storage;

class FileService
{
    static public $s3_disk = 's3';

    static public function getDefaultDisk(): string
    {
        return config('filesystems.default');
    }

    static public function create($file, string $path, string $disk = ""): string
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!$file) {
                throw new Exception('File is invalid');
            }

            if (is_string($file)) {
                return self::copy($file, $path, 'local');
            } else {
                return Storage::disk($disk)->putFile($path, $file);
            }
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function createFileWithPut($file, string $path, string $disk = ""): string
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!$file) {
                throw new Exception('File is invalid');
            }

            if (is_string($file)) {
                return self::copy($file, $path, 'local');
            } else {
                return Storage::disk($disk)->put($path, $file);
            }
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function createWithName(mixed $file, string $path, string $name, string $disk = ""): string
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();
            return Storage::disk($disk)->putFileAs($path, $file, $name);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function copyFileByStream(string $from_disk, string $from_path, string $to_disk, string $to_path): string
    {
        try {
            $inputStream = Storage::disk($from_disk)->getDriver()->readStream($from_path);
            $destination = Storage::disk($to_disk)->getDriver()->getAdapter()->getPathPrefix() . $to_path;

            return Storage::disk($to_disk)->getDriver()->putStream($destination, $inputStream);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function delete(string $path, string $disk = ""): bool
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!Storage::disk($disk)->exists($path)) {
                throw new Exception("File $path not found");
            }

            Storage::disk($disk)->delete($path);
            return true;
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return false;
        }
    }

    static public function move(string $path_from, string $path_to, string $disk = ""): bool
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!Storage::disk($disk)->exists($path_from)) {
                throw new Exception("File $path_from not found");
            }

            Storage::disk($disk)->move($path_from, $path_to);
            return true;
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return false;
        }
    }

    static public function copy(string $path_from, string $path_to, string $from_disk = "", string $to_disk = ""): string
    {
        try {
            $from_disk = $from_disk ?? self::getDefaultDisk();
            $to_disk = $to_disk ?? self::getDefaultDisk();

            if (!Storage::disk($from_disk)->exists($path_from)) {
                throw new Exception("File $path_from not found");
            }

            $file = Storage::disk($from_disk)->path($path_from);
            return Storage::disk($to_disk)->putFile($path_to, $file);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function get(string $path, string $disk = ""): string
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!Storage::disk($disk)->exists($path)) {
                throw new Exception("File $path not found");
            }

            return Storage::disk($disk)->get($path);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return '';
        }
    }

    static public function getAllFilesInFolder(string $folder_path, string $disk = ""): ?array
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();

            if (!Storage::disk($disk)->exists($folder_path)) {
                throw new Exception("File $folder_path not found");
            }

            return Storage::disk($disk)->files($folder_path);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return null;
        }
    }

    static public function exists(string $path, string $disk = ""): bool
    {
        try {
            $disk = $disk ?? self::getDefaultDisk();
            return Storage::disk($disk)->exists($path);
        } catch (Exception $ex) {
            self::writeErrorLog($ex);
            return false;
        }
    }

    static public function getUploadedFileExtension(object $file): string
    {
        $file_name = $file->getClientOriginalName();
        $file_name_array = explode('.', $file_name);
        return $file_name_array[count($file_name_array) - 1];
    }

    static public function getLogFileName(string $file_path): string
    {
        return basename($file_path);
    }

    static private function writeErrorLog(Exception $ex)
    {
        LogService::init()->critical($ex, ['error' => LogsEnum::FILE_SERVICE_ERROR]);
    }
}
