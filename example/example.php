<?php

chdir('..');
require_once('vendor/autoload.php');

$bc = new Chrl\IncrementalBackup();

system('rm -rf example/indir');
system('rm -rf example/backupdir');

// Prepare test directories
@mkdir('example/indir');
@mkdir('example/backupdir');
file_put_contents('example/indir/testfile.txt', 'testfile');

// Run sync -- should copy the file
$bc->syncDir('example/indir', 'example/backupdir');

// Change file

file_put_contents('example/indir/testfile.txt', 'testfile-changed');

// Run sync -- should copy changed file
$bc->syncDir('example/indir', 'example/backupdir');
