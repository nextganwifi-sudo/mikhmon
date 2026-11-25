<?php
/*
 * NextGen WiFi Solution - Complete Standalone Windows Server Package Exporter
 * Bundles the standalone server + portable php/ runtime + live web files
 * so the user can simply Extract -> launch server -> Click Open!
 */
require_once __DIR__ . '/../include/security.php';
ngwConfigureSecureSession();
ngwSendNoCacheHeaders();
session_start();
error_reporting(0);
@ini_set('memory_limit', '512M');
@set_time_limit(180);

include_once('../include/reseller_acl.php');

if (!isset($_SESSION["mikhmon"]) || (function_exists('ngwIsCurrentSessionRevoked') && ngwIsCurrentSessionRevoked()) || !isMikhmonAdminStore()) {
    session_write_close();
    header("HTTP/1.1 403 Forbidden");
    echo "Access Denied: Only Super Administrator can export the NextGan WiFi Solution Server package.";
    exit;
}
session_write_close();

/**
 * Pure-PHP ZIP Archive Builder (works on PHP 5.4, 7.x, and 8.x without requiring php_zip.dll)
 */
class NextGanZipBuilder {
    private $datasec = array();
    private $ctrl_dir = array();
    private $old_offset = 0;

    private function unix2DosTime($unixtime = 0) {
        $timearray = ($unixtime == 0) ? getdate() : getdate($unixtime);
        if ($timearray['year'] < 1980) {
            $timearray['year'] = 1980;
            $timearray['mon'] = 1;
            $timearray['mday'] = 1;
            $timearray['hours'] = 0;
            $timearray['minutes'] = 0;
            $timearray['seconds'] = 0;
        }
        return (($timearray['year'] - 1980) << 25)
            | ($timearray['mon'] << 21)
            | ($timearray['mday'] << 16)
            | ($timearray['hours'] << 11)
            | ($timearray['minutes'] << 5)
            | ($timearray['seconds'] >> 1);
    }

    public function addFileContent($data, $name, $time = 0) {
        $name = str_replace('\\', '/', $name);
        $dtime = substr("00000000" . dechex($this->unix2DosTime($time)), -8);
        $hexdtime = pack('H*', $dtime[6] . $dtime[7] . $dtime[4] . $dtime[5] . $dtime[2] . $dtime[3] . $dtime[0] . $dtime[1]);

        $unc_len = strlen($data);
        $crc = crc32($data);
        $zdata = gzcompress($data, 6);
        $zdata = substr(substr($zdata, 0, strlen($zdata) - 4), 2); // raw deflate stream
        $c_len = strlen($zdata);

        // Local file header
        $fr = "\x50\x4b\x03\x04";
        $fr .= "\x14\x00"; // ver needed to extract
        $fr .= "\x00\x00"; // gen purpose bit flag
        $fr .= "\x08\x00"; // compression method: deflated
        $fr .= $hexdtime;  // last mod time and date
        $fr .= pack('V', $crc); // crc32
        $fr .= pack('V', $c_len); // compressed filesize
        $fr .= pack('V', $unc_len); // uncompressed filesize
        $fr .= pack('v', strlen($name)); // length of filename
        $fr .= pack('v', 0); // extra field length
        $fr .= $name;
        $fr .= $zdata;

        $this->datasec[] = $fr;

        // Central directory record
        $cdrec = "\x50\x4b\x01\x02";
        $cdrec .= "\x00\x00"; // version made by
        $cdrec .= "\x14\x00"; // version needed to extract
        $cdrec .= "\x00\x00"; // gen purpose bit flag
        $cdrec .= "\x08\x00"; // compression method
        $cdrec .= $hexdtime;  // last mod time & date
        $cdrec .= pack('V', $crc); // crc32
        $cdrec .= pack('V', $c_len); // compressed filesize
        $cdrec .= pack('V', $unc_len); // uncompressed filesize
        $cdrec .= pack('v', strlen($name)); // length of filename
        $cdrec .= pack('v', 0); // extra field length
        $cdrec .= pack('v', 0); // file comment length
        $cdrec .= pack('v', 0); // disk number start
        $cdrec .= pack('v', 0); // internal file attributes
        $cdrec .= pack('V', 32); // external file attributes - 'archive' bit set
        $cdrec .= pack('V', $this->old_offset); // relative offset of local header
        $this->old_offset += strlen($fr);
        $cdrec .= $name;

        $this->ctrl_dir[] = $cdrec;
    }

    public function getArchiveBytes() {
        $data = implode('', $this->datasec);
        $ctrldir = implode('', $this->ctrl_dir);
        return $data
            . $ctrldir
            . "\x50\x4b\x05\x06\x00\x00\x00\x00"
            . pack('v', count($this->ctrl_dir))
            . pack('v', count($this->ctrl_dir))
            . pack('V', strlen($ctrldir))
            . pack('V', strlen($data))
            . "\x00\x00";
    }
}

$mikhmonWebRoot = realpath(__DIR__ . '/..');
$zip = new NextGanZipBuilder();

// 1. Locate standalone MikhmonServer.exe + php/ runtime directory
$bundledServerDir = realpath($mikhmonWebRoot . '/../mikhmon-server-runtime/Mikhmon Server');
if (!$bundledServerDir || !is_dir($bundledServerDir)) {
    // Fallback if already running inside a Windows Mikhmon Server folder
    $parentDir = realpath($mikhmonWebRoot . '/..');
    if ($parentDir && is_file($parentDir . '/MikhmonServer.exe') && is_dir($parentDir . '/php')) {
        $bundledServerDir = $parentDir;
    }
}

// Add MikhmonServer.exe and php/* into `Mikhmon Server/`
if ($bundledServerDir && is_dir($bundledServerDir)) {
    $exePath = $bundledServerDir . '/MikhmonServer.exe';
    if (is_file($exePath)) {
        $exeBytes = @file_get_contents($exePath);
        if ($exeBytes !== false) {
            $zip->addFileContent($exeBytes, 'Mikhmon Server/MikhmonServer.exe', @filemtime($exePath));
        }
    }
    $phpDir = $bundledServerDir . '/php';
    if (is_dir($phpDir)) {
        $phpIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($phpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($phpIterator as $phpItem) {
            if ($phpItem->isFile()) {
                $fullPhpPath = $phpItem->getRealPath();
                $relPhpPath = str_replace('\\', '/', substr($fullPhpPath, strlen($phpDir) + 1));
                $phpContent = @file_get_contents($fullPhpPath);
                if ($phpContent !== false) {
                    $zip->addFileContent($phpContent, 'Mikhmon Server/php/' . $relPhpPath, $phpItem->getMTime());
                }
            }
        }
    }
}

$skipPrefixes = array(
    '.git/', '.aistudio/', '.php-runtime/', 'node_modules/',
    'backups/', 'mikhmon/', 'mikhmonv3-upstream/', 'public/', 'src/', 'dist/'
);
$skipExact = array('.git', '.env', '.env.local', 'bun.lock', 'package-lock.json');

$webDirIt = new RecursiveDirectoryIterator($mikhmonWebRoot, RecursiveDirectoryIterator::SKIP_DOTS);
$webFilterIt = new RecursiveCallbackFilterIterator($webDirIt, function ($current) use ($mikhmonWebRoot, $skipPrefixes, $skipExact) {
    if ($current->isLink()) {
        return false;
    }
    $full = $current->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($full, strlen($mikhmonWebRoot))), '/');
    if (in_array($rel, $skipExact, true) || strpos($rel, '.DS_Store') !== false) {
        return false;
    }
    $checkRel = $current->isDir() ? ($rel . '/') : $rel;
    foreach ($skipPrefixes as $pref) {
        if (strpos($checkRel, $pref) === 0) {
            return false;
        }
    }
    return true;
});
$webIterator = new RecursiveIteratorIterator($webFilterIt, RecursiveIteratorIterator::LEAVES_ONLY);

foreach ($webIterator as $item) {
    $fullPath = $item->getRealPath();
    if (!$fullPath || strpos($fullPath, $mikhmonWebRoot) !== 0) {
        continue;
    }
    $relPath = ltrim(str_replace('\\', '/', substr($fullPath, strlen($mikhmonWebRoot))), '/');
    if ($item->isFile()) {
        $content = @file_get_contents($fullPath);
        if ($content !== false) {
            $zip->addFileContent($content, 'Mikhmon Server/mikhmon/' . $relPath, $item->getMTime());
        }
    }
}

$archiveBytes = $zip->getArchiveBytes();
$filename = 'NextGan-WiFi-Solution-Server.zip';

if (ob_get_length()) {
    @ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($archiveBytes));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

echo $archiveBytes;
exit;
