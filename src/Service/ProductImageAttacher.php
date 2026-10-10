<?php

namespace ProductImport\Service;

use ProductImport\Repository\ImageSourceRepository;

class ProductImageAttacher
{
    private static array $fingerprints = [];
    /** Number of image downloads performed by this process (for run verification). */
    public static int $downloads = 0;

    private ?ImageSourceRepository $sources = null;

    public function attach(int $idProduct, string $url): int
    {
        $this->sources ??= new ImageSourceRepository();
        $known = $this->sources->findExisting($idProduct, $url);
        if ($known !== null) {
            return $known;
        }
        $temporary = tempnam(_PS_TMP_IMG_DIR_, 'pi_');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate image temporary file.');
        }
        $image = null;
        $reused = null;
        try {
            ++self::$downloads;
            $this->downloadImage($url, $temporary);
            $fingerprinter = new ImageFingerprint();
            $fingerprint = $fingerprinter->fromFile($temporary);
            if ($fingerprint !== null) {
                try {
                    $this->sources->forgetMissingForProduct($idProduct);
                    $known = $this->existingFingerprints($idProduct, $fingerprinter);
                    foreach ($known as $idImage => $existing) {
                        if ($fingerprinter->isSame($fingerprint, $existing)) {
                            $reused = (int) $idImage;
                            break;
                        }
                    }
                } catch (\Throwable $error) {
                    // A failed lookup must not prevent the existing attachment flow.
                }
            }
            if ($reused !== null) {
                $this->sources->remember($idProduct, $url, $reused);
                return $reused;
            }
            $image = new \Image();
            $image->id_product = $idProduct;
            $image->position = (int) \Image::getHighestPosition($idProduct) + 1;
            $image->cover = \Image::getCover($idProduct) ? null : true;
            if (!$image->add()) {
                throw new \RuntimeException('Unable to create product image record.');
            }
            $image->associateTo([(int) \Context::getContext()->shop->id]);
            $path = $image->getPathForCreation();
            if (!$path || !\ImageManager::resize($temporary, $path . '.jpg')) {
                throw new \RuntimeException('Unable to write product image.');
            }
            foreach (\ImageType::getImagesTypes('products') as $type) {
                if (!\ImageManager::resize($temporary, $path . '-' . stripslashes($type['name']) . '.jpg', (int) $type['width'], (int) $type['height'])) {
                    throw new \RuntimeException('Unable to generate product image thumbnail.');
                }
            }
            if ($fingerprint !== null) {
                self::$fingerprints[$idProduct][(int) $image->id] = $fingerprint;
            }
            $this->sources->remember($idProduct, $url, (int) $image->id);
            return (int) $image->id;
        } catch (\Throwable $error) {
            if ($image !== null && $image->id) {
                $image->delete();
            }
            throw $error;
        } finally {
            unlink($temporary);
        }
    }

    private function existingFingerprints(int $idProduct, ImageFingerprint $fingerprinter): array
    {
        $idLang = (int) \Context::getContext()->language->id;
        $images = \Image::getImages($idLang, $idProduct);
        if (!is_array($images)) {
            return [];
        }
        $current = [];
        foreach ($images as $row) {
            $idImage = (int) $row['id_image'];
            $path = _PS_PRODUCT_IMG_DIR_ . \Image::getImgFolderStatic($idImage) . $idImage . '.jpg';
            if (!is_readable($path)) {
                unset(self::$fingerprints[$idProduct][$idImage]);
                continue;
            }
            if (!array_key_exists($idImage, self::$fingerprints[$idProduct] ?? [])) {
                self::$fingerprints[$idProduct][$idImage] = $fingerprinter->fromFile($path);
            }
            if (self::$fingerprints[$idProduct][$idImage] !== null) {
                $current[$idImage] = self::$fingerprints[$idProduct][$idImage];
            }
        }
        return $current;
    }

    private function downloadImage(string $url, string $destination): void
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('Image URL must use HTTP or HTTPS.');
        }
        $file = fopen($destination, 'wb');
        if ($file === false) {
            throw new \RuntimeException('Unable to open temporary image file.');
        }
        $handle = curl_init($url);
        try {
            if ($handle === false) {
                throw new \RuntimeException('Unable to initialize image download.');
            }
            $protocolOption = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS;
            $protocolValue = defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : CURLPROTO_HTTP | CURLPROTO_HTTPS;
            curl_setopt_array($handle, [
                CURLOPT_FILE => $file,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 120,
                $protocolOption => $protocolValue,
            ]);
            if (curl_exec($handle) === false) {
                throw new \RuntimeException('Image download failed: ' . curl_error($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Image download returned HTTP ' . $status . '.');
            }
        } finally {
            fclose($file);
            if (is_resource($handle)) {
                curl_close($handle);
            }
        }
    }
}
