<?php

require_once __DIR__.'/src/Collector.php';

$collector = new \Codeacious\ProfileCollector\Collector();
if ($collector->isEnabled())
{
    try
    {
        $collector->start();
    }
    catch (Throwable $e)
    {
        error_log('profile-collector: '.$e->getMessage());
    }
}

return $collector;