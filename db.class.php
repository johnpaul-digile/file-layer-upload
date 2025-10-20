<?php

require 'vendor/autoload.php';

use Dotenv\Dotenv;

/**
 * Database Connection Class
 */
class DB {
    
    /**
     * Database Connection Object
     */
    protected $connect;

    /**
     * Dotenv Object
     */
    protected $dotenv;

    /**
     * Constructor
     */
    public function __construct() {
        $this->dotenv = Dotenv::createImmutable(__DIR__);
        $this->dotenv->load();

        $connectionInfo = [
            'Database' => $_ENV['DB_NAME'], 
            'UID' => $_ENV['DB_USERNAME'], 
            'PWD' => $_ENV['DB_PASSWORD']
        ];

        $this->connect = sqlsrv_connect($_ENV['DB_SERVERNAME'], $connectionInfo);

        if (!$this->connect) {
            die(print_r(sqlsrv_errors(), true));
        }
    }

    /**
     * Check record if exists
     * 
     * @param string $sql SQL Query
     * @param array $params Query Parameters
     * 
     * @return array
     */
    public function checkIfExists($sql, $params) {
        $stmt = sqlsrv_query($this->connect, $sql, $params);
        $row = [
            'count' => 0
        ];

        if ($stmt !== false) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        }

        return $row;
    }

    /**
     * Insert File Layer Data
     * 
     * @param string $type File Type
     * @param array $params File Data
     * 
     * @return bool|resource
     */
    public function insertFileLayerData($type, $params) {
        switch ($type) {
            case 'KML':
            case 'SHP':
                $dataPoolSql = 'INSERT INTO Data_Pool (Data_Name, Data_URL, Data_Owner, Share, Data_Type, Added_Date, Offset, Data_Owner_PID, Added_By, Style, Modified_By, Modified_Date, X_Offset, Y_Offset, Timeline_Year) OUTPUT INSERTED.Data_ID, INSERTED.Data_Name VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);';
                $dataPoolQuery = sqlsrv_query($this->connect, $dataPoolSql, array_values($params['data_pool']));

                if ($dataPoolQuery !== false) {
                    $dataPoolNewRecord = sqlsrv_fetch_array($dataPoolQuery, SQLSRV_FETCH_ASSOC);
                    $params['project_layer']['Data_ID'] = $dataPoolNewRecord['Data_ID'];
                    $projectLayerSql = 'INSERT INTO Project_Layers (Data_ID, Layer_Name, Attached_Date, zindex, Default_View, Project_ID, Attached_By, layerGroup, meta_id, Modified_By, Modified_Date, show_metadata, subGroupID, subGroupName, subLayerTitle, Timeline_Year) OUTPUT INSERTED.Layer_ID, INSERTED.Data_ID, INSERTED.Layer_Name VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                
                    $projectLayerQuery = sqlsrv_query($this->connect, $projectLayerSql, array_values($params['project_layer']));

                    if ($projectLayerQuery !== false) {
                        return $projectLayerQuery;
                    }
                }

                return false;
            break;
            case 'AIC':
                $kmlSql = 'INSERT INTO AerialImageCompare (Project_Id, Package_Id, Image_Type, Image_Captured_Date, Registered_By, Registered_Date, Image_URL, Routine_Id, Routine_Type, Use_Name, Image_Group, Image_SubGroup, Owner_Id, Share, Owner_AIC_ID) OUTPUT INSERTED.AIC_Id, INSERTED.Image_URL VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $kmlQuery = sqlsrv_query($this->connect, $kmlSql, array_values($params));
                
                // if ($kmlQuery !== false) {
                //     $row = sqlsrv_fetch_array($kmlQuery, SQLSRV_FETCH_ASSOC);
                //     $aicUpdateSql = "UPDATE AerialImageCompare SET Owner_AIC_Id = ? WHERE AIC_Id = ?";
                //     $aicUpdateParams = [$row['AIC_Id'], $row['AIC_Id']];

                //     sqlsrv_query($this->connect, $aicUpdateSql, $aicUpdateParams);
                // }

                return $kmlQuery;
            break;
        }

        return false;
    }

    /**
     * Delete S# file layers data from database
     */
    public function deleteS3FileLayersData() {
        $shpkmlSql = "DELETE FROM Data_Pool WHERE Data_Type IN ('SHP', 'KML') AND Data_Name LIKE 'S3-%';";
        $aicSql = "DELETE FROM AerialImageCompare WHERE Image_URL LIKE 'S3-%';";
        $projLayerSql = "DELETE FROM Project_Layers WHERE Layer_Name LIKE 'S3-%';";

        sqlsrv_query($this->connect, $projLayerSql);
        sqlsrv_query($this->connect, $aicSql);
        sqlsrv_query($this->connect, $shpkmlSql);
    }

    /**
     * Get all KML paths
     * 
     * @return array
     */
    public function getAllKmlPaths() {
        $kmlSql = "SELECT Data_URL FROM Data_Pool WHERE Data_Type='KML' AND Data_Name LIKE 'S3-%'";

        $stmt = sqlsrv_query($this->connect, $kmlSql);

        $kmlPaths = [];

        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $kmlPaths[] = $row['Data_URL'];
            }
        }

        return $kmlPaths;
    }
}