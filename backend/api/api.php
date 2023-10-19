<?php

namespace nfsen_ng\api;

use nfsen_ng\common\{Debug, Config};

class API {
    private $method;
    private $request;
    private $input;
    private $d;
    
    public function __construct() {
        $this->d = Debug::getInstance();
        
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: deny');
        
        // try to read config
        try {
            Config::initialize();
        } catch (\Exception $e) {
            $this->error(503, $e->getMessage());
        }
        
        // get the HTTP method, path and body of the request
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->request = explode('/', trim($_GET['request'], '/'));
        $this->input = json_decode(file_get_contents('php://input'), true);
        
        // only allow GET requests
        // if at some time POST requests are enabled, check the request's content type (or return 406)
        if ($this->method !== "GET") $this->error(501);
        
        // call correct method
        if (!method_exists($this, $this->request[0])) $this->error(404);
        
        // remove method name from $_REQUEST
        $_REQUEST = array_filter($_REQUEST, function ($x) {
            return $x !== $this->request[0];
        });
        
        $method = new \ReflectionMethod($this, $this->request[0]);
        
        // check number of parameters
        if ($method->getNumberOfRequiredParameters() > count($_REQUEST)) $this->error(400, 'Not enough parameters');
        
        $args = array();
        // iterate over each parameter
        foreach ($method->getParameters() as $arg) {
            if (!isset($_REQUEST[$arg->name])) {
                if ($arg->isOptional())
                    continue;
                $this->error(400, 'Expected parameter ' . $arg->name);
            }
            
            // make sure the data types are correct
            switch ($arg->getType()->getName()) {
                case 'int':
                    if (!is_numeric($_REQUEST[$arg->name])) $this->error(400, 'Expected type int for ' . $arg->name);
                    $args[$arg->name] = intval($_REQUEST[$arg->name]);
                    break;
                case 'array':
                    if (!is_array($_REQUEST[$arg->name])) $this->error(400, 'Expected type array for ' . $arg->name);
                    $args[$arg->name] = $_REQUEST[$arg->name];
                    break;
                case 'string':
                    if (!is_string($_REQUEST[$arg->name])) $this->error(400, 'Expected type string for ' . $arg->name);
                    $args[$arg->name] = $_REQUEST[$arg->name];
                    break;
                default:
                    $args[$arg->name] = $_REQUEST[$arg->name];
            }
        }
        
        // get output
        $output = $this->{$this->request[0]}(...array_values($args));
        
        // return output
        if (array_key_exists('csv', $_REQUEST)) {
            
            // output CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=export.csv');
            $return = fopen('php://output', 'w');
            foreach ($output as $i => $line) {
                if ($i === 0) continue; // skip first line
                fputcsv($return, $line);
            }
            
            fclose($return);
            
        } else {
            
            // output JSON
            echo json_encode($output);
        }
        
    }
    
    /**
     * Helper function, returns the http status and exits the application.
     *
     * @param int    $code
     * @param string $msg
     */
    public function error(int $code, $msg = '') {
        http_response_code($code);
        $debug = Debug::getInstance();
        
        $response = array('code' => $code, 'error' => '');
        switch ($code) {
            case 400:
                $response['error'] = '400 - Bad Request. ' . (empty($msg) ? 'Probably wrong or not enough arguments.' : $msg);
                $debug->log($response['error'], LOG_INFO);
                break;
            case 401:
                $response['error'] = '401 - Unauthorized. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 403:
                $response['error'] = '403 - Forbidden. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 404:
                $response['error'] = '404 - Not found. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 501:
                $response['error'] = '501 - Method not implemented. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 503:
                $response['error'] = '503 - Service unavailable. ' . $msg;
                $debug->log($response['error'], LOG_ERR);
                break;
        }
        echo json_encode($response);
        exit();
    }
    
    /**
     * Execute the processor to get statistics
     *
     * @param int    $datestart
     * @param int    $dateend
     * @param array  $sources
     * @param string $filter
     * @param int    $top
     * @param string $for
     * @param string $limit
     * @param array  $output
     *
     * @return array
     */
    public function stats(
        int $datestart,
        int $dateend,
        array $sources,
        string $filter,
        int $top,
        string $for,
        string $limit,
        array $output = array()
    ) {
        $sources = implode(':', $sources);
        
        $processor = new Config::$processorClass();
        $processor->setOption('-M', $sources); // multiple sources
        $processor->setOption('-R', array($datestart, $dateend)); // date range
        $processor->setOption('-n', $top);
        if (array_key_exists('format', $output)) {
            $processor->setOption('-o', $output['format']);

            if ($output['format'] === 'custom' && array_key_exists('custom', $output) && !empty($output['custom'])) {
                $processor->setOption('-o', 'fmt:' . $output['custom']);
            }
        }

        $processor->setOption('-s', $for);
        if (!empty($limit)) $processor->setOption('-l', $limit); // todo -L for traffic, -l for packets
        if ($output['ipv6'] == true) $processor->setOption('-6', null);

        $processor->setFilter($filter);
        
        try {
            $return = $processor->execute();
        } catch (\Exception $e) {
            $this->error(503, $e->getMessage());
        }
        
        return $return;
    }
    
    /**
     * Execute the processor to get flows
     *
     * @param int    $datestart
     * @param int    $dateend
     * @param array  $sources
     * @param string $filter
     * @param int    $limit
     * @param string $aggregate
     * @param string $sort
     * @param array  $output
     *
     * @return array
     */
    public function flows(
        int $datestart,
        int $dateend,
        array $sources,
        string $filter,
        int $limit,
        string $aggregate,
        string $sort,
        array $output
    ) {
        $aggregate_command = "";
        // nfdump -M /srv/nfsen/profiles-data/live/tiber:n048:gate:swibi:n055:swi6  -T  -r 2017/04/10/nfcapd.201704101150 -c 20
        $sources = implode(':', $sources);
        if (!empty($aggregate))
            $aggregate_command = ($aggregate === 'bidirectional') ? '-B' : '-A' . $aggregate; // no space inbetween

        $processor = new Config::$processorClass();
        $processor->setOption('-M', $sources); // multiple sources
        $processor->setOption('-R', array($datestart, $dateend)); // date range
        $processor->setOption('-c', $limit); // limit
        $processor->setOption('-o', $output['format']);
        if ($output['format'] === 'custom' && array_key_exists('custom', $output) && !empty($output['custom'])) {
            $processor->setOption('-o', 'fmt:' . $output['custom']);
        }
        
        if (!empty($sort)) $processor->setOption('-O', 'tstart');
        if ($output['ipv6'] == true) $processor->setOption('-6', null);
        if (!empty($aggregate_command)) $processor->setOption('-a', $aggregate_command);
        $processor->setFilter($filter);
        
        try {
            $return = $processor->execute();
        } catch (\Exception $e) {
            $this->error(503, $e->getMessage());
        }
        
        return $return;
    }
    
    /**
     * Get data to build a graph
     *
     * @param int    $datestart
     * @param int    $dateend
     * @param array  $sources
     * @param array  $protocols
     * @param array  $ports
     * @param string $type
     * @param string $display
     *
     * @return array|string
     */
    public function graph(
        int $datestart,
        int $dateend,
        array $sources,
        array $protocols,
        array $ports,
        string $type,
        string $display
    ) {
        $graph = Config::$db->get_graph_data($datestart, $dateend, $sources, $protocols, $ports, $type, $display);
        if (!is_array($graph)) $this->error(400, $graph);
        return $graph;
    }
    
    public function graph_stats() {
    }
    
    /**
     * Get config info
     * @return array
     */
    public function config() {
        $sources = Config::$cfg['general']['sources'];
        $ports = Config::$cfg['general']['ports'];
        $frontend = Config::$cfg['frontend'];
        
        $stored_output_formats = array(); // todo implement
        $stored_filters = array(); // todo implement
        
        //$folder = dirname(__FILE__, 2);
        $folder = '/var/nfdump/profiles-data';
        $ports = Config::$cfg['general']['ports'];
        $pidfile = $folder . '/nfsen-ng.pid';
        $daemon_running = file_exists($pidfile);
        
        return array(
            'sources' => $sources,
            'ports' => $ports,
            'stored_output_formats' => $stored_output_formats,
            'stored_filters' => $stored_filters,
            'daemon_running' => $daemon_running,
            'frontend' => $frontend,
        );
    }
}