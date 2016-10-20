<?php

namespace Chrl;

/**
 * Class IncrementalBackup
 * @package Chrl
 */
class IncrementalBackup
{

    /**
     * @var bool Show progressbar of files count
     */
    public $showProgress = true;

    /**
     * @var int total lines count
     */
    public $totalCount = 0;
	/**
	 * @var int
	 */
	public $totalSkipped = 0;

	/**
 	 * @var bool Output debug messages via echo
	 */
	public $debugOutput = true;

	/**
	 * @var int
	 */
	protected $rotations = 0;
	/**
	 * @var int
	 */
	protected $cSign = 0;
	/**
	 * @var array
	 */
	protected $signs = ['  .','.  ',' . '];
	/**
	 * @var array
	 */
	protected $hashTable = [];
	/**
	 * @var string
	 */
	protected $primaryDir = '/';


	/**
	 * IncrementalBackup constructor.
	 * @param string $primaryDir Primary directory, where operations are done
	 */
	public function __construct($primaryDir = '')
    {
        $this->primaryDir = $primaryDir;
		return $this;
    }

	/**
	 * @param $string
	 * @return IncrementalBackup
	 */
	public function out($string)
    {
        if ($this->debugOutput) {
            echo $string;
        }
        return $this;
    }

	/**
	 * @return string Current rotating sign
	 */
	public function getIncSign()
    {
        if ($this->rotations++ > 500) {
            $this->rotations = 0;
            $this->cSign++;

            if ($this->cSign>2) {
                $this->cSign = 0;
            }
        }
        return $this->signs[$this->cSign];
    }

	/**
	 * Scan directory and convert in into array
	 *
	 * @param string $directory Directory path to scan
	 * @param bool $recursive Scan it recursively or not?
	 * @return array
	 */
	public function directoryToArray($directory, $recursive)
    {
        $array_items = array();
        if ($handle = @opendir($directory)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    if (is_dir($directory. "/" . $file)) {
                        if ($recursive) {
                            $array_items = array_merge(
                                $array_items,
                                $this->directoryToArray($directory . "/" . $file, $recursive)
                            );
                        }
                    }

                    $file = $directory . "/" . $file;
                    $array_items[ preg_replace("/\/\//si", "/", $file)] =
                        [
                            'size'=>filesize($file),
                            'mtime'=>@filemtime($file),
                            'type'=>is_dir($file) ? 'dir':'file'
                        ];
                    $this->totalCount++;
                    $this->oneStringDebug(' counting: '.$this->totalCount.', skipped '.$this->totalSkipped);
                }
            }
            closedir($handle);
        } else {
            $this->totalSkipped++;
        }
        return $array_items;
    }

	/**
	 * Output debug message with rotating sign and from the start of the line
	 *
	 * @param string $string Message
	 * @return IncrementalBackup
	 */
	public function oneStringDebug($string)
    {
        $this->out("\r[".$this->getIncSign()."] ".$string);
        flush();
        return $this;
    }

	/**
	 * Scan directory and fill the hash table
	 *
	 * @param string $dir Directory to scan
	 * @return IncrementalBackup
	 */
	public function dirScan($dir)
    {

        $this->primaryDir = $dir;

        $this->out('Scanning dir '.$dir."\n");
        $fileTable = $this->directoryToArray($dir, true);
        $this->out("\rGot ".count($fileTable)." items, count ".$this->totalCount.
            ", skipped ".$this->totalSkipped.". Building hashtable...\n");
        foreach ($fileTable as $file => $attributes) {
            $this->hashTable[md5(str_replace($this->primaryDir, '', $file))] =
                [
                    md5(str_replace($this->primaryDir, '', $file)),
                    $file,
                    $attributes['type'],
                    $attributes['size'],
                    $attributes['mtime']
                ];
        }
        return $this;
    }

	/**
	 * Write hash table into file
	 *
	 * @param string $filename File to write
	 * @return IncrementalBackup
	 */
	public function writeHashTable($filename = 'filehashes.list')
    {
        $str = '';
        foreach ($this->hashTable as $line) {
            $str.=implode('||', $line)."\n";
        }
        file_put_contents($filename, $str);
        return $this;
    }

	/**
	 * Getter for hashtable
	 *
	 * @return array Hashtable
	 */
	public function getHashTable()
    {
        return $this->hashTable;
    }

	/**
	 * Load hashtable from file
	 *
	 * @param string $filename File to read
	 * @return IncrementalBackup
	 */
	public function loadHashTable($filename)
    {
        $file = file($filename);
        foreach ($file as $line) {
            $line = explode('||', trim($line));
            $this->hashTable[$line[0]] = $line;
        }
        return $this;
    }

	/**
	 * Compare hashtable of this object to hashtable of another object of this type and get the difference
	 *
	 * @param IncrementalBackup $ib
	 * @return array Difference array
	 */
	public function compareWith(IncrementalBackup $ib)
    {
        $diff = [
         'added'=>[],
         'deleted'=>[],
         'changed'=>[],
        ];

        $sizeDiffers = 0;
        $mtimeDiffers = 0;

        $fHashes = $ib->getHashTable();

        foreach ($this->hashTable as $file => $line) {
            if (!isset($fHashes[$file])) {
                $diff['added'][$file] = $line;
                continue;
            }

            $fLine = $fHashes[$file];

            if ($line[2] == 'file') {
                if ($line[2] != $fLine[2]) {
                    $diff['changed'][$file] = $line;
                    $sizeDiffers++;
                } elseif ($line[3]!=$fLine[3]) {
                    $diff['changed'][$file] = $line;
                    $mtimeDiffers++;
                }
            }
        }

        foreach ($fHashes as $file => $line) {
            if (!isset($this->hashTable[$file])) {
                $diff['deleted'][$file] = $line;
            }
        }

        $diff['size_differs'] = $sizeDiffers;
        $diff['mtime_differs'] = $mtimeDiffers;

        return $diff;
    }

	/**
	 * Apply diff, copying changed and added files to the second object
	 *
	 * @param string $prefix Prefix of files on the hashtable
	 * @param array $diff Diff to apply
	 * @return IncrementalBackup
	 */
	public function applyDiff($prefix, array $diff)
    {

        $cpCount = count($diff['added']);
        $this->out('Copying new files: '.$cpCount."\n");
        $copied = 0;
        foreach ($diff['added'] as $line) {
            $copied++;
            $this->oneStringDebug(' '.$copied.' of '.$cpCount.', '.(round(10000*$copied/$cpCount)/100).'%   ');
            $this->copyFile($line, $prefix, $this->primaryDir);
        }
        $this->out('Copied files: '.count($diff['added'])."\n");

        $cpCount = count($diff['changed']);
        $this->out('Copying changed files: '.$cpCount."\n");
        $copied = 0;
        foreach ($diff['changed'] as $line) {
            $copied++;
            $this->oneStringDebug(' '.$copied.' of '.$cpCount.', '.(round(10000*$copied/$cpCount)/100).'%   ');
            $this->copyFile($line, $prefix, $this->primaryDir);
        }
        $this->out('Copied changed files: '.count($diff['changed'])."\n");
		return $this;
    }

	/**
	 * Copy a file to another location
	 *
	 * @param array $line line with metadata about file
	 * @param string $prefix initial dir
	 * @param string $toDir resulting dir
	 */
	public function copyFile(array $line, $prefix, $toDir)
    {

        $newName = str_replace($prefix, $toDir, $line[1]);

        if ($line[2]=='dir') {
            if (!file_exists($newName)) {
                mkdir($newName, 0777, true);
            }
        } else {
            if (!file_exists(dirname($newName))) {
                mkdir(dirname($newName), 0777, true);
            }
            copy($line[1], $newName);
        }
    }

	/**
	 * Actually the main method -- start with it. It syncs main and backup dirs
	 * @see examples
	 *
	 * @param $currentDir
	 * @param string $backupDir
	 */
	public function syncDir($currentDir, $backupDir = '')
    {

        $this->dirScan($currentDir);

        $backup = new IncrementalBackup($backupDir);

        if (file_exists($backupDir.'/hashtable.last')) {
            $this->out('Loading previous state of hash table'."\n");
            $backup->loadHashTable($backupDir.'/hashtable.last');
        } else {
            $backup->dirScan($backupDir)->writeHashTable($backupDir.'/hashtable.last');
        }

        $diff = $this->compareWith($backup);

        $this->out('Diff: '.count($diff['added']). ' files added, '.count($diff['changed']).' changed ('.
            $diff['size_differs'].
            ' size, '.$diff['mtime_differs'].' mtime) and '.count($diff['deleted']).' deleted.'."\n");

        $backup->applyDiff($currentDir, $diff);
        $this->writeHashTable($backupDir.'/hashtable.last');
    }
}
