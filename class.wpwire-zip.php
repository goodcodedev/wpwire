<?php

abstract class Wpwire_Zip {

    /**
     * @return Wpwire_Zip
     */
    public static function create() {
        if (class_exists('ZipArchive', false)) {
            return new Wpwire_Zip_Impl_ZipArchive();
        } else {
            return new Wpwire_Zip_Impl_PclZip();
        }
    }

    abstract function open($zipFileName);

    abstract function addFile($absFile, $removePath, $addPath);

    abstract function addDir($absDir, $removePath, $addPath);

    abstract function close();
}

class Wpwire_Zip_Impl_ZipArchive extends Wpwire_Zip {
    public $zip;

    public function open($fileName) {
        $this->zip= new ZipArchive();
        $this->zip->open($fileName, ZIPARCHIVE::CREATE);
    }

    public function addFile($fileName, $removePath, $addPath = null) {
        if ($addPath !== null) {
            $this->zip->addFile($fileName, $addPath.substr($fileName, strlen($removePath) + 1));
        } else {
            $this->zip->addFile($fileName, substr($fileName, strlen($removePath) + 1));
        }
    }

    public function addDir($dirName, $removePath, $addPath = null) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName));
        $relLen = strlen($removePath);
        $this->zip->addEmptyDir(substr($dirName, $relLen + 1));
        foreach ($files as $name => $file) {
            $fileName = $file->getFileName();
            if ($fileName == '.' || $fileName == '..') {
                continue;
            }
            if ($addPath !== null) {
                $relPath = $addPath.substr($name, $relLen + 1);
            } else {
                $relPath = substr($name, $relLen + 1);
            }
            if ($file->isDir()) {
                $this->zip->addEmptyDir($relPath);
            } else {
                $this->zip->addFile($name, $relPath);
            }
        }
    }

    public function close() {
        $this->zip->close();
    }
} 

require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

class Wpwire_Zip_Impl_PclZip extends Wpwire_Zip {
    /**
     * @var PclZip
     */
    public $zip;

    public function open($fileName) {
        $this->zip = new PclZip($fileName);
    }

    public function addFile($fileName, $removePath, $addPath = null) {
        if ($addPath !== null) {
            $this->zip->add(
                array($fileName),
                PCLZIP_OPT_ADD_PATH, $addPath,
                PCLZIP_OPT_REMOVE_PATH, $removePath
            );
        } else {
            $this->zip->add(
                array($fileName),
                PCLZIP_OPT_REMOVE_PATH, $removePath
            );
        }
    }

    public function addDir($dirName, $removePath, $addPath = null) {
        if ($addPath !== null) {
            $this->zip->add(
                array($dirName),
                PCLZIP_OPT_ADD_PATH, $addPath,
                PCLZIP_OPT_REMOVE_PATH, $removePath
            );
        } else {
            $this->zip->add(
                array($dirName),
                PCLZIP_OPT_REMOVE_PATH, $removePath
            );
        }
    }

    public function close() {
    }
} 