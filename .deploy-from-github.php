<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ARCHIVE_URL = 'https://codeload.github.com/shabahren/website/zip/refs/heads/main';
const SITE_ROOT = '/home/hanawama/domains/mohsenmofidi.com/public_html';

$temporaryDirectory = sys_get_temp_dir() . '/website-deploy-' . bin2hex(random_bytes(6));
$archivePath = $temporaryDirectory . '/website.zip';
$extractedRoot = $temporaryDirectory . '/website-main';

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

function copyDirectory(string $source, string $destination): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $relativePath = $items->getSubPathName();

        if ($relativePath === 'README.md' || str_starts_with($relativePath, '.github/')) {
            continue;
        }

        $target = $destination . '/' . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException("Unable to create directory: {$target}");
            }
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("Unable to copy file: {$relativePath}");
        }
    }
}

try {
    if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Unable to create the temporary deployment directory.');
    }

    $curl = curl_init(ARCHIVE_URL);
    $archiveHandle = fopen($archivePath, 'wb');

    if ($curl === false || $archiveHandle === false) {
        throw new RuntimeException('Unable to initialize the download.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FILE => $archiveHandle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FAILONERROR => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'mohsenmofidi.com DirectAdmin deployer',
    ]);

    $downloaded = curl_exec($curl);
    $downloadError = curl_error($curl);
    curl_close($curl);
    fclose($archiveHandle);

    if ($downloaded !== true) {
        throw new RuntimeException("Download failed: {$downloadError}");
    }

    $archive = new ZipArchive();

    if ($archive->open($archivePath) !== true || !$archive->extractTo($temporaryDirectory)) {
        throw new RuntimeException('Unable to extract the repository archive.');
    }

    $archive->close();

    if (!is_dir($extractedRoot)) {
        throw new RuntimeException('The repository archive has an unexpected structure.');
    }

    copyDirectory($extractedRoot, SITE_ROOT);
    @unlink(SITE_ROOT . '/.deployment-error');
    file_put_contents(SITE_ROOT . '/.deployment-version', gmdate(DATE_ATOM) . PHP_EOL);
} catch (Throwable $error) {
    file_put_contents(
        SITE_ROOT . '/.deployment-error',
        gmdate(DATE_ATOM) . ' ' . $error->getMessage() . PHP_EOL
    );
    throw $error;
} finally {
    removeDirectory($temporaryDirectory);
}
