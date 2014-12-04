<?php

/**
 * Author: Lucas van Staden
 * Used by developers to grab a snapshot of databases
 * requires an xml file for configuration which is placed in app/etc/snapshot.xml
 *
 */
require_once 'abstract.php';

/**
 * Snapshot shell script
 * @author      Lucas van Staden
 */
class ProxiBlue_Shell_Snapshot extends Mage_Shell_Abstract {

    protected $_includeMage = false;
    protected $_localDB = null;
    protected $_configXml = null;
    protected $_snapshotXml = null;
    protected $_db = null;
    protected $_snapshot = null;
    protected $_ignoreTables = null;

    public function __construct() {

        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';

        parent::__construct();
        $localXML = $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'local.xml';
        $this->_configXml = simplexml_load_string(file_get_contents($localXML));
        $this->_snapshotXml = $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'snapshot.xml';
        if (!file_exists($this->_snapshotXml)) {
            die("Your config file is missing. {$this->_snapshotXml}");
        }
        $this->_snapshotXml = simplexml_load_string(file_get_contents($this->_snapshotXml));

        $rootpath = $this->_getRootPath();
        $this->_snapshot = $rootpath . 'snapshot';

        # Create the snapshot directory if not exists
        if (!file_exists($this->_snapshot)) {
            mkdir($this->_snapshot);
        }
    }

    /**
     * Run script
     */
    public function run() {
        set_time_limit(0);
        if ($this->getArg('export-remote')) {
            $this->_export($this->getArg('export-remote'));
        } else if ($this->getArg('import')) {
            $this->_import($this->getArg('import'));
        } else if ($this->getArg('fetch')) {
            $this->_export($this->getArg('fetch'));
            $this->_import($this->getArg('fetch'));
        } else if ($this->getArg('copy')) {
            $this->_copy($this->getArg('copy'));
        } else if ($this->getArg('copy-to-remote')) {
            $this->_copy_to_remote($this->getArg('copy-to-remote'),$this->getArg('dbname'));
        } else {
            echo $this->usageHelp();
        }
    }

    /**
     * Perform snapshot
     */
    function _export($profile) {
        $timestamp = time();
        $connection = $this->_snapshotXml->$profile->connection;
        if (!$connection) {
            echo "Could not find a snapshot configuration for " . $profile;
            echo $this->usageHelp();
            die();
        }

        if (empty($connection->ssh_port)) {
            $connection->ssh_port = 22;
        }

        $structureOnly = $this->_snapshotXml->structure;
        $this->_ignoreTables = " --ignore-table={$connection->dbname}." . implode(" --ignore-table={$connection->dbname}.", explode(',', $structureOnly->ignore_tables));


        # Dump the database
        echo "Extracting structure...\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'mysqldump --single-transaction -d -h localhost -u {$connection->db_username} --password='{$connection->db_password}' {$connection->dbname} | gzip > \"{$profile}_structure_" . $timestamp . ".sql.gz\"'");
        passthru("scp -P {$connection->ssh_port} {$connection->ssh_username}@{$connection->host}:~/{$profile}_structure_" . $timestamp . ".sql.gz {$this->_snapshot}/{$profile}_structure.sql.gz");
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'rm -rf ~/{$profile}_structure_" . $timestamp . ".sql.gz'");

        echo "Extracting data...\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'mysqldump --single-transaction -h localhost -u {$connection->db_username} --password='{$connection->db_password}' {$connection->dbname} $this->_ignoreTables | gzip > \"{$profile}_data_" . $timestamp . ".sql.gz\"'");
        passthru("scp -P {$connection->ssh_port} {$connection->ssh_username}@{$connection->host}:~/{$profile}_data_" . $timestamp . ".sql.gz {$this->_snapshot}/{$profile}_data.sql.gz");
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'rm -rf ~/{$profile}_data_" . $timestamp . ".sql.gz'");

        echo "Done\n";
    }

    function _copy($newName) {

        $structureOnly = $this->_snapshotXml->structure;
        $this->_ignoreTables = " --ignore-table={$this->_configXml->global->resources->default_setup->connection->dbname}." . implode(" --ignore-table={$this->_configXml->global->resources->default_setup->connection->dbname}.", explode(',', $structureOnly->ignore_tables));

        $this->getConnection();
        # Dump the database to a local copy
        echo "Extracting structure...\n";
        passthru("mysqldump --single-transaction -d -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname} >> {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql");
        echo "Extracting data...\n";
        passthru("mysqldump --single-transaction -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname} $this->_ignoreTables >> {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql");

        // create new db
        echo "Creating Database: " . $this->_configXml->global->resources->default_setup->connection->dbname . "_{$newName}\n";
        passthru("mysqladmin -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' create {$this->_configXml->global->resources->default_setup->connection->dbname}_$newName");

        // import to new db
        $pv = "";
        $hasPv = shell_exec("which pv");
        echo "Importing structure...\n";
        passthru("{$pv} cat {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql | mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}_{$newName}");
        echo "Importing data...{$pv} cat {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql | mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}_{$newName}\n";
        passthru("{$pv} cat {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql | mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}_{$newName}");

        //cleanup
        unlink("{$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql");
        unlink("{$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql");

        echo "A copy of {$this->_configXml->global->resources->default_setup->connection->dbname} was made to {$this->_configXml->global->resources->default_setup->connection->dbname}-${newName}";
    }

    function _copy_to_remote($profile,$dbName=null) {

        $connection = $this->_snapshotXml->$profile->connection;

        if (!$connection) {
            echo "Could not find a snapshot configuration for " . $profile;
            echo $this->usageHelp();
            die();
        }

        if (empty($connection->ssh_port)) {
            $connection->ssh_port = 22;
        }

        if(is_null($dbName)){
            $dbName = $this->_configXml->global->resources->default_setup->connection->dbname;
        }

        $structureOnly = $this->_snapshotXml->structure;
        $this->_ignoreTables = " --ignore-table={$this->_configXml->global->resources->default_setup->connection->dbname}." . implode(" --ignore-table={$this->_configXml->global->resources->default_setup->connection->dbname}.", explode(',', $structureOnly->ignore_tables));

        $this->getConnection();
        # Dump the database to a local copy
        echo "Extracting structure...\n";
        passthru("mysqldump --single-transaction -d -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname} > {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql");
        echo "Extracting data...\n";
        passthru("mysqldump --single-transaction -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname} $this->_ignoreTables > {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql");

        // create new db on the remote
        echo "Creating Database on Remote: {$dbName}\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'mysqladmin -h localhost -u {$connection->db_username} --password='{$connection->db_password}' create {$dbName}'");

        //copy the exported files to the remote
        echo "Copy dump files to Remote\n";
        passthru("scp -P {$connection->ssh_port} {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql {$connection->ssh_username}@{$connection->host}:~/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql");
        passthru("scp -P {$connection->ssh_port} {$this->_snapshot}/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql {$connection->ssh_username}@{$connection->host}:~/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql");

        // import to new remote db
        echo "Importing structure...\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'mysql  -h localhost -u {$connection->db_username} --password='{$connection->db_password}' {$dbName} < ~/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql'");
        echo "Importing data...\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'mysql  -h localhost -u {$connection->db_username} --password='{$connection->db_password}' {$dbName} < ~/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql'");

        //cleanup
        echo "Cleanup - removing remote dump files...\n";
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'rm -rf ~/{$this->_configXml->global->resources->default_setup->connection->dbname}_structure.sql'");
        passthru("ssh -p {$connection->ssh_port} {$connection->ssh_username}@{$connection->host} 'rm -rf ~/{$this->_configXml->global->resources->default_setup->connection->dbname}_data.sql'");

        echo "Database was copied to remote...";
    }

    function _import($profile) {

        $rootpath = $this->_getRootPath();
        $this->_snapshot = $rootpath . 'snapshot';

        echo "Dropping Database: " . $this->_configXml->global->resources->default_setup->connection->dbname . "\n";
        passthru("mysqladmin -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' drop {$this->_configXml->global->resources->default_setup->connection->dbname}");

        echo "Creating Database: " . $this->_configXml->global->resources->default_setup->connection->dbname . "\n";
        passthru("mysqladmin -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' create {$this->_configXml->global->resources->default_setup->connection->dbname}");

        // import structure
        $pv = "";
        $hasPv = shell_exec("which pv");
        if (!empty($hasPv)) {
            echo "Structure...\n";
            echo "Extracting...\n";
            passthru("gzip -d {$this->_snapshot}/{$profile}_structure.sql.gz");
            echo "Importing...\n";
            passthru("pv {$this->_snapshot}/{$profile}_structure.sql | mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}");
            echo "Repacking...\n";
            passthru("gzip {$this->_snapshot}/{$profile}_structure.sql");
            echo "Data...\n";
            echo "Extracting...\n";
            passthru("gzip -d {$this->_snapshot}/{$profile}_data.sql.gz");
            echo "Importing...\n";
            passthru("pv {$this->_snapshot}/{$profile}_data.sql | mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}");
            echo "Repacking...\n";
            passthru("gzip {$this->_snapshot}/{$profile}_data.sql");
        } else {
            echo "install pv ( sudo apt-get install pv ) to get a progress indicator for importing!\n";

            echo "Importing structure...\n";
            passthru("gunzip -c {$this->_snapshot}/{$profile}_structure.sql.gz | {$pv} mysql  -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}");
            // import data
            echo "Importing data...\n";
            passthru("gunzip -c {$this->_snapshot}/{$profile}_data.sql.gz | {$pv} mysql -h {$this->_configXml->global->resources->default_setup->connection->host} -u {$this->_configXml->global->resources->default_setup->connection->username} --password='{$this->_configXml->global->resources->default_setup->connection->password}' {$this->_configXml->global->resources->default_setup->connection->dbname}");
        }

        // lets manipulate the database.
        // at this pont we can instantiate the magento system, as the datbaase is now imported.
        $this->getConnection();

        // common import updates
        foreach ($this->_snapshotXml->import as $key => $importUpdates) {
            echo "GLOBAL CHANGES\n";
            $this->importUpdates($importUpdates);
        }

        // site specific import updates
        foreach ($this->_snapshotXml->$profile->import as $key => $importUpdates) {
            echo "PROFILE CHANGES\n";
            $this->importUpdates($importUpdates);
        }
    }

    private function getConnection() {
        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';
        Mage::app($this->_appCode, $this->_appType);
        try {
            $this->_db = Zend_Db::factory('Pdo_Mysql', array(
                        'host' => $this->_configXml->global->resources->default_setup->connection->host,
                        'username' => $this->_configXml->global->resources->default_setup->connection->username,
                        'password' => $this->_configXml->global->resources->default_setup->connection->password,
                        'dbname' => $this->_configXml->global->resources->default_setup->connection->dbname
            ));
            $this->_db->getConnection();
        } catch (Zend_Db_Adapter_Exception $e) {
            mage::throwException($e);
            die($e->getMessage());
        } catch (Zend_Exception $e) {
            mage::throwException($e);
            die($e->getMessage());
        }

    }

    private function importUpdates($importUpdates) {
        foreach ($importUpdates as $tableName => $changes) {
            foreach ($changes as $changeKey => $updateData) {
                switch ($changeKey) {
                    case 'update':
                        try {
                            $this->_db->getProfiler()->setEnabled(true);
                            $where = $updateData->where->field . " = '" . $updateData->where->value . "'";
                            $this->_db->update($tableName, array((string) $updateData->set->field => (string) $updateData->set->value), $where);
                            echo "UPDATE: {$tableName} {$where}\n";
                        } catch (Exception $e) {
                            echo"Failed to do an update:";
                            Zend_Debug::dump($this->_db->getProfiler()->getLastQueryProfile()->getQuery());
                            Zend_Debug::dump($this->_db->getProfiler()->getLastQueryProfile()->getQueryParams());
                            $this->_db->getProfiler()->setEnabled(false);
                        }
                        break;
                    case 'delete':
                        try {
                            $this->_db->getProfiler()->setEnabled(true);
                            $where = $updateData->where->field . " = '" . $updateData->where->value . "'";
                            $this->_db->delete($tableName, $where);
                            echo "DELETE: {$tableName} {$where}\n";
                        } catch (Exception $e) {
                            echo"Failed to do a delete:";
                            Zend_Debug::dump($this->_db->getProfiler()->getLastQueryProfile()->getQuery());
                            Zend_Debug::dump($this->_db->getProfiler()->getLastQueryProfile()->getQueryParams());
                            $this->_db->getProfiler()->setEnabled(false);
                        }
                        break;
                    default:
                        echo "import method {$changeKey} not yet implemented!";
                        break;
                }
            }
        }
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp() {
        global $argv;
        $self = basename($argv[0]);
        return <<<USAGE

Snapshot

Saves a tarball of the media directory and a gzipped database dump
taken with mysqldump

Usage:  php -f $self -- [options]

Options:

  help              This help

  --fetch <profile> Do export and import in one go.  Current database will be replaced with update
  --export-remote <profile>  Take snapshot of the given remote server [must be defined in snapshot.xml]
  --import <profile> Import the given snapshot
  --copy [newname] Copy the current (local) database to a new database - will create a copy of the local db with the current name prefixed to the given new name. Used for branching in GIT
  --copy-to-remote <profile> [--dbname <name of db>] Copy the local db to the remote server. If no dbname is given it will be named as is the local db name

USAGE;
    }

}

if (basename($argv[0]) == basename(__FILE__)) {
    $shell = new ProxiBlue_Shell_Snapshot();
    $shell->run();
}
