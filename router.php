<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path === '/api/schools') {require __DIR__.'/Backend/api.php';return true;}
if ($path === '/' || preg_match('~^/lessons/[1-9][0-9]*/?$~',$path)) {$path='/index.html';}
$file=realpath(__DIR__.'/Frontend'. $path);
$base=realpath(__DIR__.'/Frontend');
if(!$file||!str_starts_with($file,$base.'/')||!is_file($file)){http_response_code(404);echo 'Not found';return true;}
$types=['css'=>'text/css','js'=>'application/javascript','html'=>'text/html'];$ext=pathinfo($file,PATHINFO_EXTENSION);header('Content-Type: '.($types[$ext]??'application/octet-stream').'; charset=utf-8');readfile($file);
