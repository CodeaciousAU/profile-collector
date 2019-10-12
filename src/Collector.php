<?php

namespace Codeacious\ProfileCollector;

use MongoDB\BSON;
use MongoDB\Client;
use RuntimeException;

class Collector
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $aggregationUrl;

    /**
     * @var array
     */
    protected $serverVars;

    /**
     * @var array
     */
    protected $envVars;

    /**
     * @var bool
     */
    protected $running = false;

    /**
     * @var bool
     */
    protected $shutdownFunctionRegistered = false;


    /**
     * @param array $config
     */
    public function __construct(array $config=[])
    {
        $defaults = [
            'enabled' => !!getenv('PROFILING_ENABLE'),
            'ratio' => intval(getenv('PROFILING_RATIO')) ?: 100,
            'mongodb' => [
                'uri' => getenv('PROFILING_DB_URI'),
                'uri_options' => [
                    'appname' => 'profile-collector',
                    'username' => getenv('PROFILING_DB_USERNAME'),
                    'password' => getenv('PROFILING_DB_PASSWORD'),
                ],
                'driver_options' => [],
                'database_name' => getenv('PROFILING_DB_NAME'),
                'collection_name' => getenv('PROFILING_COLLECTION_NAME') ?: 'results',
            ],
            'profiler_options' => [],
            'fastcgi_finish_request' => true,
            'collect_server_vars' => true,
            'collect_env_vars' => true,
        ];
        $this->config = array_replace_recursive($defaults, $config);
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        if ($this->url !== null)
            return $this->url;

        $uri = array_key_exists('REQUEST_URI', $_SERVER) ? $_SERVER['REQUEST_URI'] : null;
        if (empty($uri) && isset($_SERVER['argv']))
        {
            $cmd = basename($_SERVER['argv'][0]);
            $uri = $cmd.' '.implode(' ', array_slice($_SERVER['argv'], 1));
        }
        return $uri;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return string
     */
    public function getAggregationUrl()
    {
        if ($this->aggregationUrl !== null)
            return $this->aggregationUrl;

        return $this->getUrl();
    }

    /**
     * @param string $aggregationUrl
     * @return $this
     */
    public function setAggregationUrl($aggregationUrl)
    {
        $this->aggregationUrl = $aggregationUrl;
        return $this;
    }

    /**
     * @return array
     */
    public function getServerVars()
    {
        if ($this->serverVars !== null)
            return $this->serverVars;

        return $_SERVER;
    }

    /**
     * @param array $serverVars
     * @return $this
     */
    public function setServerVars($serverVars)
    {
        $this->serverVars = $serverVars;
        return $this;
    }

    /**
     * @return array
     */
    public function getEnvVars(): array
    {
        if ($this->envVars !== null)
            return $this->envVars;

        return $_ENV;
    }

    /**
     * @param array $envVars
     * @return $this
     */
    public function setEnvVars($envVars)
    {
        $this->envVars = $envVars;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->config['enabled'];
    }

    /**
     * @return bool
     */
    public function isSupported()
    {
        return extension_loaded('mongodb') && (
            extension_loaded('uprofiler')
            || extension_loaded('tideways')
            || extension_loaded('tideways_xhprof')
        );
    }

    /**
     * @return bool
     */
    public function isRunning()
    {
        return $this->running;
    }

    /**
     * @return bool
     * @throws RuntimeException
     */
    public function start()
    {
        if ($this->running || !$this->isEnabled())
            return false;

        if (!$this->isSupported())
        {
            throw new RuntimeException('Missing PHP extension: Profiling requires mongodb and a '
                .'supported profiling extension');
        }

        //Randomise the chance of running based on the configured ratio
        if (mt_rand(1, 100) > $this->config['ratio'])
            return false;

        //Ensure presence of start time variables
        if (!isset($_SERVER['REQUEST_TIME']))
            $_SERVER['REQUEST_TIME'] = time();
        if (!isset($_SERVER['REQUEST_TIME_FLOAT']))
            $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);

        //Start the profiler
        if (extension_loaded('uprofiler'))
        {
            uprofiler_enable(
                UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY,
                $this->config['profiler_options']
            );
        }
        else if (extension_loaded('tideways'))
        {
            tideways_enable(
                TIDEWAYS_FLAGS_CPU | TIDEWAYS_FLAGS_MEMORY | TIDEWAYS_FLAGS_NO_SPANS,
                $this->config['profiler_options']
            );
        }
        else if (extension_loaded('tideways_xhprof'))
        {
            tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_CPU | TIDEWAYS_XHPROF_FLAGS_MEMORY);
        }

        //Register function to save the data when the current request ends
        if (!$this->shutdownFunctionRegistered)
        {
            register_shutdown_function([$this, 'onShutdown']);
            $this->shutdownFunctionRegistered = true;
        }

        $this->running = true;
        return true;
    }

    /**
     * @return bool
     * @throws RuntimeException
     */
    public function stop()
    {
        if (!$this->running)
            return false;

        //Stop the profiler and get the results
        $profile = null;
        if (extension_loaded('uprofiler'))
            $profile = uprofiler_disable();
        else if (extension_loaded('tideways'))
            $profile = tideways_disable();
        else if (extension_loaded('tideways_xhprof'))
            $profile = tideways_xhprof_disable();
        $this->running = false;

        //Save the results
        $this->save([
            'profile' => $profile,
            'meta' => $this->generateMetadata(),
        ]);

        return true;
    }

    /**
     * @return void
     */
    public function onShutdown()
    {
        try
        {
            ignore_user_abort(true);
            if (function_exists('session_write_close'))
                session_write_close();

            flush();
            if ($this->config['fastcgi_finish_request'] && function_exists('fastcgi_finish_request'))
                fastcgi_finish_request();

            $this->stop();
        }
        catch (\Throwable $e)
        {
            error_log('profile-collector: '.$e->getMessage());
        }
    }

    /**
     * @return array
     */
    protected function generateMetadata()
    {
        $time = $_SERVER['REQUEST_TIME'];
        $timeFloat = $_SERVER['REQUEST_TIME_FLOAT'];
        if (is_string($timeFloat))
            $timeFloat = floatval(str_replace(',', '.', $timeFloat));

        return [
            'url' => $this->getUrl(),
            'simple_url' => $this->getAggregationUrl(),
            'SERVER' => $this->config['collect_server_vars'] ? $this->getServerVars() : [],
            'get' => $_GET,
            'env' => $this->config['collect_env_vars'] ? $this->getEnvVars() : [],
            'request_ts' => $time*1000.0,
            'request_ts_micro' => $timeFloat*1000.0,
            'request_date' => date('Y-m-d', $time),
        ];
    }

    /**
     * @param array $data
     * @return BSON\ObjectId
     */
    protected function save(array $data)
    {
        if (isset($data['meta']['request_ts']))
            $data['meta']['request_ts'] = new BSON\UTCDateTime($data['meta']['request_ts']);

        if (isset($data['meta']['request_ts_micro']))
            $data['meta']['request_ts_micro'] = new BSON\UTCDateTime($data['meta']['request_ts_micro']);

        $client = new Client(
            $this->config['mongodb']['uri'],
            $this->config['mongodb']['uri_options'],
            $this->config['mongodb']['driver_options']
        );
        $dbName = $this->config['mongodb']['database_name'];
        $collectionName = $this->config['mongodb']['collection_name'];

        return $client->$dbName->$collectionName->insertOne($data)
            ->getInsertedId();
    }
}