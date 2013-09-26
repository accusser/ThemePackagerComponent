<?php
namespace Siphon\Actions;

/**
 * Extract a Snapshot from a MODX Instance.
 */
class Extract extends Action {
    /**
     * @var array Defines the fields required for the Extract action.
     */
    public $required = array('profile', 'tpl');
    /**
     * @var \modX An instance of modX to Extract a Snapshot from
     */
    public $modx;
    /**
     * @var \modPackageBuilder A package builder instance from the \modX instance.
     */
    public $builder;
    /**
     * @var \xPDOTransport The Snapshot transport package Extracted from the \modX instance.
     */
    public $package;

    /**
     * Process the Extract action.
     *
     * @throws \Siphon\RequestException If an error is encountered during processing.
     */
    public function process() {
        parent::process();
        try {
            $this->profile = $this->loadProfile($this->request->profile);
            $this->tpl = $this->loadTpl($this->request->tpl);

            if (!defined('MODX_CORE_PATH')) define('MODX_CORE_PATH', $this->profile->properties->modx->core_path);
            if (!defined('MODX_CONFIG_KEY')) define('MODX_CONFIG_KEY', !empty($this->profile->properties->modx->config_key) ? $this->profile->properties->modx->config_key : 'config');

            $this->initMODX();

            $this->prepareTpl();

            $this->modx->loadClass('transport.xPDOTransport', XPDO_CORE_PATH, true, true);
            $this->modx->loadClass('transport.xPDOVehicle', XPDO_CORE_PATH, true, true);
            $this->modx->loadClass('transport.xPDOObjectVehicle', XPDO_CORE_PATH, true, true);

            $this->package = $this->createPackage($this->profile->code . '_' . $this->tpl['name'], $this->getVersion(), $this->getSequence());

            foreach ($this->tpl['vehicles'] as $vehicle) {
                $this->createVehicles($vehicle);
            }

            if (!$this->package->pack()) {
                throw new \Siphon\RequestException("Error packing {$this->package->signature}.transport.zip", $this->request->results);
            }
            $this->request->log("Successfully extracted {$this->package->signature}.transport.zip from instance {$this->profile->code}");
            if ($this->target && $this->push) {
                if (!$this->push()) {
                    throw new \Siphon\RequestException("Error pushing {$this->package->signature}.transport.zip to {$this->target}", $this->request->results);
                }

                if (!$this->preserveWorkspace && $this->modx->getCacheManager()) {
                    $this->modx->cacheManager->deleteTree($this->package->path . $this->package->signature);
                    @unlink($this->package->path . $this->package->signature . '.transport.zip');
                }

                $this->request->log("Successfully pushed {$this->package->signature}.transport.zip to {$this->target}");
                $this->request->log("{$this->target}{$this->package->signature}.transport.zip", false);
            } else {
                $this->request->log("{$this->package->path}{$this->package->signature}.transport.zip", false);
            }
        } catch (\Exception $e) {
            throw new \Siphon\RequestException("Error Extracting snapshot: " . $e->getMessage(), $this->request->results, $e);
        }
    }

    /**
     * Load the JSON extract tpl data into a PHP array.
     *
     * @param $tpl A valid stream or file location for the tpl.
     * @return array An array of the tpl data.
     */
    protected function loadTpl($tpl) {
        return json_decode(file_get_contents($tpl), true);
    }

    /**
     * Parse the tpl replacing placeholders from the profile.
     */
    protected function prepareTpl() {
        $this->modx->loadClass('modParser', '', false, true);
        $parser = new \Siphon\Parser($this->modx);
        $this->modx->toPlaceholders($this->profile);
        $tpl = $this->tpl;
        array_walk_recursive($tpl, function(&$value, $key, $parser) {
            if (is_string($value)) {
                $parser->processElementTags('', $value);
            }
        }, $parser);
        $this->tpl = $tpl;
    }

    public function createPackage($name, $version, $release = '') {
        /* setup the signature and filename */
        $s['name'] = strtolower($name);
        $s['version'] = $version;
        $s['release'] = $release;
        $signature = $s['name'];
        if (!empty ($s['version'])) {
            $signature .= '-' . $s['version'];
        }
        if (!empty ($s['release'])) {
            $signature .= '-' . $s['release'];
        }
        $filename = $signature . '.transport.zip';

        /* remove the package if it's already been made */
        $directory = $this->getWorkspaceDir();
        if (file_exists($directory . $filename)) {
            unlink($directory . $filename);
        }
        if (file_exists($directory . $signature) && is_dir($directory . $signature)) {
            $cacheManager = $this->modx->getCacheManager();
            if ($cacheManager) {
                $cacheManager->deleteTree($directory . $signature, true, false, array());
            }
        }

        /* create the transport package */
        $this->package = new \Siphon\Transport\SiphonTransport($this->modx, $signature, $directory);
        $this->request->log("Created new transport package with signature: {$signature}");

        return $this->package;
    }

    /**
     * Get a package version for this snapshot.
     *
     * @return string The package version string.
     */
    public function getVersion() {
        return strftime('%y%m%d.%H%M.%S');
    }

    /**
     * Get a package sequence for this snapshot.
     *
     * @return string The package sequence string.
     */
    public function getSequence() {
        return $this->modx->version['full_version'];
    }

    /**
     * Create \xPDOVehicle instances from MODX assets and data to go into the Snapshot.
     *
     * @param object $vehicle A vehicle definition from the Extract tpl being applied.
     * @return int The number of vehicles created from the definition.
     */
    public function createVehicles($vehicle) {
        $vehicleCount = 0;
        switch ($vehicle['vehicle_class']) {
            case 'xPDOObjectVehicle':
                $realClass = $this->modx->loadClass($vehicle['object']['class']);
                $graph = isset($vehicle['object']['graph']) && is_array($vehicle['object']['graph']) ? $vehicle['object']['graph'] : array();
                $graphCriteria = isset($vehicle['object']['graphCriteria']) && is_array($vehicle['object']['graphCriteria']) ? $vehicle['object']['graphCriteria'] : null;
                if (isset($vehicle['object']['script'])) {
                    include SIPHON_BASE_PATH . 'tpl/scripts/' . $vehicle['object']['script'];
                } elseif (isset($vehicle['object']['criteria'])) {
                    $iterator = $this->modx->getIterator($vehicle['object']['class'], (array)$vehicle['object']['criteria'], false);
                    foreach ($iterator as $object) {
                        /** @var \xPDOObject $object */
                        if (!empty($graph)) {
                            $object->getGraph($graph, $graphCriteria, false);
                        }
                        if ($this->package->put($object, $vehicle['attributes'])) {
                            $vehicleCount++;
                        }
                    }
                } elseif (isset($vehicle['object']['data'])) {
                    /** @var \xPDOObject $object */
                    $object = $this->modx->newObject($vehicle['object']['class']);
                    if ($object instanceof $realClass) {
                        $object->fromArray($vehicle['object']['data'], '', true, true);
                        if ($this->package->put($object, $vehicle['attributes'])) {
                            $vehicleCount++;
                        }
                    }
                }
                $this->request->log("Packaged {$vehicleCount} xPDOObjectVehicles for class {$vehicle['object']['class']}");
                break;
            case '\\Siphon\\Transport\\SiphonXPDOCollectionVehicle':
                $objCnt = 0;
                $realClass = $this->modx->loadClass($vehicle['object']['class']);
                $graph = isset($vehicle['object']['graph']) && is_array($vehicle['object']['graph']) ? $vehicle['object']['graph'] : array();
                $graphCriteria = isset($vehicle['object']['graphCriteria']) && is_array($vehicle['object']['graphCriteria']) ? $vehicle['object']['graphCriteria'] : null;
                if (isset($vehicle['object']['script'])) {
                    include SIPHON_BASE_PATH . 'tpl/scripts/' . $vehicle['object']['script'];
                } elseif (isset($vehicle['object']['criteria'])) {
                    $limit = isset($vehicle['object']['limit']) ? (integer)$vehicle['object']['limit'] : 0;
                    if ($limit < 1) {
                        $limit = 500;
                    }
                    $offset = 0;
                    $criteria = $this->modx->newQuery($vehicle['object']['class'], (array)$vehicle['object']['criteria'], false);
                    $set = $this->modx->getCollection($vehicle['object']['class'], $criteria->limit($limit, $offset), false);
                    while (!empty($set)) {
                        foreach ($set as &$object) {
                            /** @var \xPDOObject $object */
                            if (!empty($graph)) {
                                $object->getGraph($graph, $graphCriteria, false);
                            }
                        }
                        if (!empty($set) && $this->package->put($set, $vehicle['attributes'])) {
                            $vehicleCount++;
                            $objCnt = $objCnt + count($set);
                        }
                        $offset += $limit;
                        $set = $this->modx->getCollection($vehicle['object']['class'], $criteria->limit($limit, $offset), false);
                    }
                }
                $this->request->log("Packaged {$vehicleCount} SiphonXPDOCollectionVehicles with {$objCnt} total objects for class {$vehicle['object']['class']}");
                break;
            case '\\Siphon\\Transport\\SiphonMySQLVehicle':
                /* collect table names from classes and grab any additional tables/data not listed */
                $modxDatabase = $this->modx->getOption('dbname', null, $this->modx->getOption('database'));
                $modxTablePrefix = $this->modx->getOption('table_prefix', null, '');

                $coreTables = array();
                foreach ($vehicle['object']['classes'] as $class) {
                    $coreTables[$class] = $this->modx->quote($this->modx->literal($this->modx->getTableName($class)));
                }

                $stmt = $this->modx->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$modxDatabase}' AND TABLE_NAME NOT IN (" . implode(',', $coreTables) . ")");
                $extraTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                if (is_array($extraTables) && !empty($extraTables)) {
                    $excludeExtraTablePrefix = isset($vehicle['object']['excludeExtraTablePrefix']) && is_array($vehicle['object']['excludeExtraTablePrefix']) ? $vehicle['object']['excludeExtraTablePrefix'] : array();
                    $excludeExtraTables = isset($vehicle['object']['excludeExtraTables']) && is_array($vehicle['object']['excludeExtraTables']) ? $vehicle['object']['excludeExtraTables'] : array();
                    foreach ($extraTables as $extraTable) {
                        if (in_array($extraTable, $excludeExtraTables)) continue;

                        $instances = 0;
                        $object = array(
                            'vehicle_package' => '',
                            'vehicle_class' => '\\Siphon\\Transport\\SiphonMySQLVehicle'
                        );
                        $attributes = array(
                            'vehicle_package' => '',
                            'vehicle_class' => '\\Siphon\\Transport\\SiphonMySQLVehicle'
                        );

                        /* remove modx table_prefix if table starts with it */
                        $extraTableName = $extraTable;
                        if (!empty($modxTablePrefix) && strpos($extraTableName, $modxTablePrefix) === 0) {
                            $extraTableName = substr($extraTableName, strlen($modxTablePrefix));
                            $addTablePrefix = true;
                        } elseif (!empty($modxTablePrefix) || in_array($extraTableName, $excludeExtraTablePrefix)) {
                            $addTablePrefix = false;
                        } else {
                            $addTablePrefix = true;
                        }
                        $object['tableName'] = $extraTableName;
                        $this->request->log("Extracting non-core table {$extraTableName}");

                        /* generate the CREATE TABLE statement */
                        $stmt = $this->modx->query("SHOW CREATE TABLE {$this->modx->escape($extraTable)}");
                        $resultSet = $stmt->fetch(\PDO::FETCH_NUM);
                        $stmt->closeCursor();
                        if (isset($resultSet[1])) {
                            if ($addTablePrefix) {
                                $object['drop'] = "DROP TABLE IF EXISTS {$this->modx->escape('[[++table_prefix]]' . $extraTableName)}";
                                $object['table'] = str_replace("CREATE TABLE {$this->modx->escape($extraTable)}", "CREATE TABLE {$this->modx->escape('[[++table_prefix]]' . $extraTableName)}", $resultSet[1]);
                            } else {
                                $object['drop'] = "DROP TABLE IF EXISTS {$this->modx->escape($extraTableName)}";
                                $object['table'] = $resultSet[1];
                            }

                            /* collect the rows and generate INSERT statements */
                            $object['data'] = array();
                            $stmt = $this->modx->query("SELECT * FROM {$this->modx->escape($extraTable)}");
                            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                                if ($instances === 0) {
                                    $fields = implode(', ', array_map(array($this->modx, 'escape'), array_keys($row)));
                                }
                                $values = array();
                                while (list($key, $value) = each($row)) {
                                    switch (gettype($value)) {
                                        case 'string':
                                            $values[] = $this->modx->quote($value);
                                            break;
                                        case 'NULL':
                                        case 'array':
                                        case 'object':
                                        case 'resource':
                                        case 'unknown type':
                                            $values[] = 'NULL';
                                            break;
                                        default:
                                            $values[] = (string) $value;
                                            break;
                                    }
                                }
                                $values = implode(', ', $values);
                                if ($addTablePrefix) {
                                    $object['data'][] = "INSERT INTO {$this->modx->escape('[[++table_prefix]]' . $extraTableName)} ({$fields}) VALUES ({$values})";
                                } else {
                                    $object['data'][] = "INSERT INTO {$this->modx->escape($extraTable)} ({$fields}) VALUES ({$values})";
                                }
                                $instances++;
                            }
                        }

                        if (!$this->package->put($object, $attributes)) {
                            $this->request->log("Could not package rows for table {$extraTable}");
                        } else {
                            $this->request->log("Packaged {$instances} rows for non-core table {$extraTable}");
                            $vehicleCount++;
                        }
                    }
                    $this->request->log("Packaged {$vehicleCount} {$vehicle['vehicle_class']} vehicles for non-core tables");
                } else {
                    $this->request->log("No non-core tables found for packaging");
                }
                break;
            case 'xPDOFileVehicle':
            case 'xPDOScriptVehicle':
            case 'xPDOTransportVehicle':
            default:
                if (isset($vehicle['object']['script'])) {
                    include SIPHON_BASE_PATH . 'tpl/scripts/' . $vehicle['object']['script'];
                } else {
                    if ($this->package->put($vehicle['object'], $vehicle['attributes'])) {
                        $this->request->log("Packaged 1 {$vehicle['vehicle_class']}" . (isset($vehicle['object']['source']) ? " from {$vehicle['object']['source']}" : ""));
                        $vehicleCount++;
                    }
                }
                break;
        }
        return $vehicleCount;
    }

    /**
     * Push the extracted Snapshot to a MODX Repository target.
     *
     * @return bool True if the snapshot was pushed successfully to the target.
     */
    public function push() {
        $pushed = false;
        if ($this->modx->getCacheManager()) {
            $pushed = $this->modx->cacheManager->copyFile(
                $this->package->path . $this->package->signature . '.transport.zip',
                $this->target . $this->package->signature . '.transport.zip',
                array('copy_preserve_permissions' => true)
            );
        }
        return $pushed;
    }
}
