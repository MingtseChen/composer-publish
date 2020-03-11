<?php

namespace Starlux\Publish;

use Composer\Package\Archiver\ArchivableFilesFinder;
use Composer\Package\Archiver\ZipArchiver;
use Composer\Util\Filesystem;
use ZipArchive;

/**
 * @author Jan Prieser <jan@prieser.net>
 */
class Zip extends ZipArchiver
{

    /**
     * {@inheritdoc}
     */
    public function archive($sources, $target, $format, array $excludes = array(), $ignoreFilters = false)
    {
        $fs = new Filesystem();
        $sources = $fs->normalizePath($sources);

        //add prefix for zip
        $prefix = basename($sources).'/';

        $zip = new ZipArchive();
        $res = $zip->open($target, ZipArchive::CREATE);
        if ($res === true) {
            $files = new ArchivableFilesFinder($sources, $excludes, $ignoreFilters);
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $filepath = strtr($file->getPath()."/".$file->getFilename(), '\\', '/');
                $localName = $prefix.str_replace($sources.'/', '', $filepath);
                if ($file->isDir()) {
                    $zip->addEmptyDir($localName);
                } else {
                    $zip->addFile($filepath, $localName);
                }
            }
            if ($zip->close()) {
                return $target;
            }
        }
        $message = sprintf(
            "Could not create archive '%s' from '%s': %s",
            $target,
            $sources,
            $zip->getStatusString()
        );
        throw new \RuntimeException($message);
    }
}
