<?php


namespace Audentio\SportsdataioPhpGenerator;


use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use mikehaertl\tmp\File;
use splitbrain\phpcli\CLI;
use splitbrain\phpcli\Options;

/**
 * Class Generator
 * @package Audentio\SportsdataioPhpGenerator
 */
class Generator extends CLI
{
    protected $config;

    protected $endpoints;

    protected $httpClient;

    protected function setup(Options $options)
    {
        $options->setHelp('Generate a Sportsdata.io API Consumer Client in PHP.');
        $options->registerOption('config', 'Path to configuration file.', 'c');
        $this->httpClient = new Client();
    }

    /**
     * @param Options $options
     * @return void
     * @throws GuzzleException
     */
    protected function main(Options $options): void
    {
        $inputArgs = $options->getArgs();
        $this->parseConfig($inputArgs);

        foreach ($this->config['endpoints'] as $endpoint) {
            $this->generateEndpointLibrary($endpoint);
        }
    }

    /**
     * @param string $endpoint
     * @throws GuzzleException
     */
    protected function generateEndpointLibrary(string $endpoint): void
    {
        $versions = $this->config['versions'];

        $routes = [];
        if (in_array('v2', $versions)) {
            $routes += $this->endpoints[$endpoint]['routes-v2'];
        }
        if (in_array('v3', $versions)) {
            $routes += $this->endpoints[$endpoint]['routes-v3'];
        }

        $fullSchema = $this->requestSchemasAndMerge($endpoint, $routes);

        $directory = getcwd();
        $tempDir = $directory . '/temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $dirSep = DIRECTORY_SEPARATOR;
        $janeSchema = new File(str_replace("\/", '/', json_encode($fullSchema, JSON_PRETTY_PRINT)), '.json');
        $tempSchema = $tempDir . $dirSep . 'jane-schema.json';
        $janeSchema->saveAs($tempSchema);

        $janeConfigContent = "<?php return [
            'openapi-file' => '{$tempSchema}',
            'namespace' => 'Sportsdata\\API\\{$endpoint}',
            'directory' =>  '{$directory}{$dirSep}{$this->config['output-directory']}{$dirSep}{$endpoint}'
        ];";
        $janeConfig = new File($janeConfigContent, '.php');
        $tempConfig = $tempDir . $dirSep . 'jane-config.php';
        $janeConfig->saveAs($tempConfig);

        $execFileName = (stripos(PHP_OS, 'WIN') !== false) ? 'jane-openapi.bat' : 'jane-openapi';
        $execFilePath = __DIR__ . '/../vendor/bin/' . $execFileName;

        $this->info('Generating library for endpoint: ' . $endpoint);
        $result = exec($execFilePath . ' generate --config-file="' . $tempConfig . '"', $output, $exitCode);
        $output = join("\n", array_filter(array_map('trim', $output)));

        if($output || $result === false || $exitCode != 0) {
            $this->error('Could not generate library for endpoint: ' . $endpoint);
        }
        else {
            $this->success('Generated library for endpoint: ' . $endpoint);
        }
    }

    /**
     * @param string $endpoint
     * @param array $routes
     * @return array
     */
    protected function requestSchemasAndMerge(string $endpoint, array $routes): array
    {
        $fullSchema = [];
        $seenOperationIds = [];

        foreach ($routes as $route => $routeUrl) {
            if (!in_array($route, $this->config['routes'])) {
                continue;
            }

            $json = json_decode($this->httpClient->get($routeUrl)->getBody()->getContents(), true);

            $pathParts = explode('/', trim($json['basePath'], '/'));
            $basePath = '/' . array_shift($pathParts) . '/' . array_shift($pathParts);
            $pathPrefix = join('/', $pathParts);

            if (empty($fullSchema)) {
                $fullSchema = array_replace($json, [
                    'basePath' => $basePath,
                    'info' => [
                        'title' => $endpoint,
                        'description' => $endpoint . ' API',
                        'version' => $json['info']['version']
                    ],
                    'paths' => [],
                    'definitions' => []
                ]);
            }

            foreach ($json['paths'] as $path => $pathConfig) {
                $path = '/' . $pathPrefix . $path;
                foreach($pathConfig as $operation => &$operationConfig) {
                    if(in_array($operationConfig['operationId'], $seenOperationIds)) {
                        // Already retrieved endpoint from different definition file
                        unset($pathConfig[$operation]);
                        continue;
                    }

                    $seenOperationIds[] = $operationConfig['operationId'];

                    usort($operationConfig['parameters'], function($param1, $param2) {
                        // Sort format to end
                        if($param1['name'] == 'format') {
                            return 1;
                        }
                        if($param2['name'] == 'format') {
                            return -1;
                        }

                        // Sort parameters with default values to back
                        if($param1['default'] ?? false) {
                            return 1;
                        }
                        if($param2['default'] ?? false) {
                            return -1;
                        }

                        // Sort required parameters to front
                        if($param1['required'] ?? false) {
                            return -1;
                        }
                        if($param2['required'] ?? false) {
                            return 1;
                        }

                        // Keep remaining order
                        return -1;
                    });

                    foreach($operationConfig['parameters'] as &$parameter) {
                        switch($parameter['name']) {
                            case 'format':
                                $parameter['default'] = 'JSON';
                                $parameter['enum'] = ['JSON', 'XML'];
                                break;
                        }
                    }

                    foreach ($operationConfig['responses'] as &$opResponse) {
                        $opResponse['description'] = $opResponse['description'] ?? "";
                    }
                }

                $fullSchema['paths'][$path] = $pathConfig;
            }

            foreach ($json['definitions'] as $definition => &$definitionConfig) {
                $fullSchema['definitions'][$definition] = $definitionConfig;
            }
        }

        $this->swapNullable($fullSchema);
        return $fullSchema;
    }

    protected function swapNullable(array &$item) {
        foreach($item as $key => &$value) {
            if(is_array($value)) {
                $this->swapNullable($value);
            }
            elseif($key == 'nullable') {
                $item['x-nullable'] = $item['nullable'];
                unset($item['nullable']);
            }
        }
    }

    /**
     * @param array $arguments
     * @return void
     * @throws Exception
     */
    protected function parseConfig(array $arguments): void
    {
        if (!empty($arguments['endpoints-config'])) {
            $endpointsConfigPath = $arguments['endpoints-config'];
        } else {
            $endpointsConfigPath = __DIR__ . '/../endpoints.json';
        }
        if (file_exists($endpointsConfigPath)) {
            $this->endpoints = json_decode(file_get_contents($endpointsConfigPath), true);
        } else {
            throw new Exception('Configuration file not found under: ' . $endpointsConfigPath);
        }

        if (!empty($arguments['config'])) {
            $configPath = $arguments['config'];
        } else {
            $configPath = __DIR__ . '/../config.default.json';
        }
        if (file_exists($configPath)) {
            $this->config = array_replace([
                'versions' => ['v2', 'v3'],
                'endpoints' => array_keys($this->endpoints),
                'routes' => array_unique(array_merge(
                    array_merge(...array_map("array_keys", array_column($this->endpoints, 'routes-v3'))),
                    array_merge(...array_map("array_keys", array_column($this->endpoints, 'routes-v2')))
                ))
            ], json_decode(file_get_contents($configPath), true));

        } else {
            throw new Exception('Configuration file not found under: ' . $configPath);
        }
    }
}