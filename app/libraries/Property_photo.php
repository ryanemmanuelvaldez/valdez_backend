<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Property_photo
{
    private const MAX_SIZE = 5242880;
    private const MAX_PIXELS = 20000000;

    public function store(array $upload): string
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = $error === UPLOAD_ERR_NO_FILE
                ? 'Choose a property photo from your device.'
                : 'The property photo upload failed. Choose a JPG/JPEG image under 5 MB and try again.';
            throw new InvalidArgumentException($message);
        }

        $temporary_path = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_SIZE || !is_uploaded_file($temporary_path)) {
            throw new InvalidArgumentException('Choose a valid JPG/JPEG image no larger than 5 MB.');
        }

        $file_info = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $file_info->file($temporary_path);
        $image_info = @getimagesize($temporary_path);
        if ($mime_type !== 'image/jpeg' || !$image_info || ($image_info[2] ?? null) !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('Only genuine JPG/JPEG property photos are accepted.');
        }

        $width = (int) ($image_info[0] ?? 0);
        $height = (int) ($image_info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException('The property photo dimensions are too large. Choose an image under 20 megapixels.');
        }

        $directory = ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'property-images';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Property photo storage could not be created.');
        }
        $filename = bin2hex(random_bytes(16)) . '.jpg';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($temporary_path, $destination)) {
            throw new RuntimeException('The property photo could not be stored.');
        }
        chmod($destination, 0640);

        return 'local:' . $filename;
    }

    public function file_path(string $stored_photo): ?string
    {
        if (!preg_match('/^local:([a-f0-9]{32}\.jpg)$/D', $stored_photo, $matches)) {
            return null;
        }

        $directory = realpath(ROOT_DIR . 'runtime' . DIRECTORY_SEPARATOR . 'property-images');
        $path = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $matches[1]);
        if ($directory === false || $path === false || strpos($path, $directory . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            return null;
        }
        return $path;
    }

    public function delete(string $stored_photo): void
    {
        $path = $this->file_path($stored_photo);
        if ($path !== null && !unlink($path)) {
            throw new RuntimeException('The replaced property photo could not be removed.');
        }
    }
}
