<?php

declare(strict_types=1);

namespace App\Service;

use ZipArchive;

class ZipGenerator
{
    /**
     * Create a ZIP archive from an array of files and stream it to the browser.
     *
     * @param string $zipFilename The filename for the ZIP download
     * @param array $files Array of ['path' => '/tmp/file.csv', 'name' => 'file.csv']
     */
    public function createAndDownload(string $zipFilename, array $files): void
    {
        $tempZipPath = sys_get_temp_dir() . '/' . uniqid('export_', true) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive');
        }

        foreach ($files as $file) {
            if (!file_exists($file['path'])) {
                continue;
            }
            $zip->addFile($file['path'], $file['name']);
        }

        $zip->close();

        // Stream the ZIP to browser
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Content-Length: ' . filesize($tempZipPath));
        header('Cache-Control: max-age=0');

        readfile($tempZipPath);

        // Cleanup
        unlink($tempZipPath);
        foreach ($files as $file) {
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }
        }
    }

    /**
     * Create a ZIP archive and save to the specified path. Cleans up source files.
     *
     * @param string $outputPath Where to save the ZIP file
     * @param array $files Array of ['path' => '/tmp/file.csv', 'name' => 'file.csv']
     */
    public function createToFile(string $outputPath, array $files): void
    {
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive');
        }

        foreach ($files as $file) {
            if (!file_exists($file['path'])) {
                continue;
            }
            $zip->addFile($file['path'], $file['name']);
        }

        $zip->close();

        // Cleanup source files
        foreach ($files as $file) {
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }
        }
    }
}
