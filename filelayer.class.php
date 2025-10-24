<?php

require 'vendor/autoload.php';
require_once 'db.class.php';

use Aws\S3\S3Client;
use League\Flysystem\Filesystem;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Dotenv\Dotenv;

class FileLayer {
    protected $dotenv;
    protected $s3Client;
    protected $adapter;
    protected $filesystem;
    protected $data = [];
    protected $response = [
        'success' => false,
        'message' => 'Invalid operation.'
    ];
    protected $filePath = [
        'SHP' => 'Geoserver/Shapefile/',
        'AIC' => 'Geoserver/AIC/',
        'KML' => 'KML/'
    ];
    protected $DB;

    /**
     * Constructor
     * 
     * @param array $params
     * 
     * @return void
     */
    public function __construct($data) {
        //se
        $this->data = $data;

        $this->dotenv = Dotenv::createImmutable(__DIR__);
        $this->dotenv->load();

        // Create S3 client
        $this->s3Client = new S3Client([
            'region' => 'ap-southeast-2', // change to your region
            'version' => 'latest',
            'credentials' => [
                'key'    => $_ENV['AWS_CLIENT'],
                'secret' => $_ENV['AWS_SECRET']
            ],
            'http' => [
                'verify' => false  // disables SSL check 🚨
            ]
        ]);

        // Setup adapter, filesystem and database connection
        $this->adapter = new AwsS3V3Adapter($this->s3Client, $_ENV['AWS_BUCKET']);
        $this->filesystem = new Filesystem($this->adapter);
        $this->DB = new DB();
    }

    /**
     * Download files from S3 bucket
     * 
     * @return array
     */
    public function download() 
    {
        try {
            // Delete downloaded files first
            $this->deleteDownloadedFiles();

            // Set file path and file name
            $keyOrFolder = $this->data['fileLayer'];
            $filePath = $this->filePath[$this->data['fileType']];
            $savedFileName = '';
            $project = $this->data['project'];
            $geoFileLayers = [];
            $dateTime = date('Y-m-d H:i:s');

            // Check file type and download accordingly
            if ($this->data['fileType'] === 'SHP') {
                // Check if file layer already exists in DB
                $dataPoolSql = 'SELECT COUNT(*) as count FROM Data_Pool WHERE Data_Name =?';
                $dataPoolSqlParams = ['S3-' . $keyOrFolder];
                $dataPool = $this->DB->checkIfExists($dataPoolSql, $dataPoolSqlParams);
                
                if ($dataPool['count'] > 0) {
                    $this->response['message'] = "File layer already exists: S3-{$keyOrFolder}";

                    return $this->response;
                }

                // Fetch file layer from S3 bucket
                $result = $this->s3Client->listObjectsV2([
                    'Bucket' => $_ENV['AWS_BUCKET'],
                    'Prefix' => $filePath . $keyOrFolder,
                    'MaxKeys' => 1,
                ]);

                // Return error response if folder not found or empty in S3
                if (empty($result['Contents'])) {
                    $this->response['message'] = "Folder not found or empty in S3: {$keyOrFolder}\n";
                    
                    return $this->response;
                }

                // Download all files inside folder
                $objects = $this->s3Client->getPaginator('ListObjectsV2', [
                    'Bucket' => $_ENV['AWS_BUCKET'],
                    'Prefix' => $filePath . $keyOrFolder,
                ]);

                // Upload file to Geoserver
                foreach ($objects as $page) {
                    if (!isset($page['Contents'])) continue;
                    
                    foreach ($page['Contents'] as $object) {
                        if (substr($object['Key'], -1) === '/') continue;
                        
                        $key = $object['Key'];
                        // Check SHP file extensions
                        $fileType = strtolower(pathinfo($key, PATHINFO_EXTENSION));
                        $allowedExtensions = ['shp', 'shx', 'dbf', 'sbn', 'sbx', 'fbn', 'fbx', 'ain', 'aih', 'atx', 'ixs', 'mxs', 'prj', 'xml', 'cpg'];
                        
                        if (!in_array($fileType, $allowedExtensions)) {
                            $this->response['message'] = "Invalid file type: {$key}\n";
                            
                            return $this->response;
                        }

                        // Save file to the server
                        $savedFileName = 'S3-' . pathinfo($key, PATHINFO_BASENAME);
                        $projDir = $project['project_id_number'] . '/S3-' . $keyOrFolder;
                        $savePath = __DIR__ . '/../Data/S3_FILES/' . $filePath . '/' . $projDir . '/';

                        if (!is_dir($savePath)) {
                            mkdir($savePath, 0777, true);
                        }

                        $savePath = $savePath . $savedFileName;

                        $this->s3Client->getObject([
                            'Bucket' => $_ENV['AWS_BUCKET'],
                            'Key'    => $key,
                            'SaveAs' => $savePath
                        ]);

                        $geoFileLayers[] = [
                            'fileName' => $savedFileName,
                            'fileType' => $this->data['fileType'],
                            'filePath' => '../Data/S3_FILES/Geoserver/Shapefile/' . $project['project_id_number'] . '/' . 'S3-' . $keyOrFolder . '/' . $savedFileName,
                            'folderName' => 'S3-' . $keyOrFolder,
                            'projectId' => $project['project_id_number']
                        ];
                    }

                    $geoServerUpload = $this->sendToGeoServer($geoFileLayers);

                    if (!$geoServerUpload['success']) {
                        return $geoServerUpload;
                    }

                    // Insert file layer data to DB
                    $shpParams = [
                        'data_pool' => [
                            'Data_Name' => 'S3-' . $keyOrFolder,
                            'Data_URL' => '../../../Data/Geoserver/Shapefile/' . $projDir,
                            'Data_Owner' => $project['project_name'],
                            'Share' => 0,
                            'Data_Type' => $this->data['fileType'],
                            'Added_Date' => $dateTime,
                            'Offset' => 0,
                            'Data_Owner_PID' => $project['project_id_number'],
                            'Added_By' => $this->data['email'],
                            'Style' => null,
                            'Modified_By' => null,
                            'Modified_Date' => null,
                            'X_Offset' => 0,
                            'Y_Offset' => 0,
                            'Timeline_Year' => null
                        ],
                        'project_layer' => [
                            'Data_ID' => null,
                            'Layer_Name' => 'S3-' . $keyOrFolder,
                            'Attached_Date' => $dateTime,
                            'zindex' => 1,
                            'Default_View' => 0,
                            'Project_ID' => $project['project_id_number'],
                            'Attached_By' => $this->data['email'],
                            'layerGroup' => null,
                            'meta_id' => null,
                            'Modified_By' => null,
                            'Modified_Date' => null,
                            'show_metadata' => null,
                            'subGroupID' => null,
                            'subGroupName' => null,
                            'subLayerTitle' => null,
                            'Timeline_Year' => null
                        ]
                    ];

                    $fileLayerInsert = $this->DB->insertFileLayerData($this->data['fileType'], $shpParams);

                    if ($fileLayerInsert === false) {
                        $this->response['message'] = "File layer data not inserted to DB: {$keyOrFolder}\n";
                        
                        return $this->response;
                    }

                    $this->response = [
                        'success' => true,
                        'message' => 'All files downloaded successfully.'
                    ];

                    $this->deleteDownloadedFiles();

                    return $this->response;
                }
            } else {
                $fileLayerSql = $this->data['fileType'] === 'KML' ? 'SELECT COUNT(*) as count FROM Data_Pool WHERE Data_Name =?;' : 'SELECT COUNT(*) as count FROM AerialImageCompare WHERE Image_URL =?;';
                $fileLayerSqlParams = ['S3-' . $keyOrFolder];
                $fileLayerData = $this->DB->checkIfExists($fileLayerSql, $fileLayerSqlParams);
                
                if ($fileLayerData['count'] > 0) {
                    $this->response['message'] = "File layer already exists: {$keyOrFolder}";

                    return $this->response;
                }

                $allowedExtensions = $this->data['fileType'] === 'AIC' ? ['ecw', 'tiff'] : ['kml', 'kmz'];
                $fileType = strtolower(pathinfo($keyOrFolder, PATHINFO_EXTENSION));

                if (!in_array($fileType, $allowedExtensions)) {
                    $this->response['message'] = "Invalid file type: {$fileType} for {$keyOrFolder}\n";
                    
                    return $this->response;
                }

                // Download single file
                $dirName = $this->data['fileType'] === 'KML' ? 'KML/' : 'Geoserver/AIC/';
                $savedFileName = 'S3-' . $keyOrFolder;
                $savePath = __DIR__ . '/../Data/S3_FILES/' . $dirName . $project['project_id_number'] . '/';

                if (!is_dir($savePath)) {
                    mkdir($savePath, 0777, true);
                }

                $savePath = $savePath . $savedFileName;

                $this->s3Client->getObject([
                    'Bucket' => $_ENV['AWS_BUCKET'],
                    'Key'    => $dirName . $keyOrFolder,
                    'SaveAs' => $savePath
                ]);

                if ($this->data['fileType'] === 'AIC') {
                    $geoFileLayers[] = [
                        'fileName' => $savedFileName,
                        'fileType' => $this->data['fileType'],
                        'filePath' => '../Data/S3_FILES/Geoserver/AIC/' . $project['project_id_number'] . '/',
                        'projectId' => $project['project_id_number']
                    ];
                    $geoServerUpload = $this->sendToGeoServer($geoFileLayers);

                    if (!$geoServerUpload['success']) {
                        return $geoServerUpload;
                    }
                }

                $kmlaicParams = $this->data['fileType'] === 'AIC' ? [
                    'Project_Id' => $project['parent_project_id_number'] ?? $project['project_id_number'],
                    'Package_Id' => $project['project_id_number'],
                    'Image_Type' => $this->data['fileType'],
                    'Image_Captured_Date' => $dateTime,
                    'Registered_By' => $this->data['email'],
                    'Registered_Date' => $dateTime,
                    'Image_URL' => 'S3-' . $keyOrFolder,
                    'Routine_Id' => 'aic_monthly_' . (date('n') - 1) . '_' . date('Y'),
                    'Routine_Type' => 0,
                    'Use_Name' => null,
                    'Image_Group' => null,
                    'Image_SubGroup' => null,
                    'Owner_Id' => $project['project_id_number'],
                    'Share' => 0,
                    'Owner_AIC_ID' => 0
                ] : [
                    'data_pool' => [
                        'Data_Name' => $savedFileName,
                        'Data_URL' => '../../../Data/Geoserver/Shapefile/' . $project['project_id_number'] . '/' . $savedFileName,
                        'Data_Owner' => $project['project_name'],
                        'Share' => 0,
                        'Data_Type' => $this->data['fileType'],
                        'Added_Date' => $dateTime,
                        'Offset' => 0,
                        'Data_Owner_PID' => $project['parent_project_id_number'],
                        'Added_By' => $this->data['email'],
                        'Style' => null,
                        'Modified_By' => null,
                        'Modified_Date' => null,
                        'X_Offset' => 0,
                        'Y_Offset' => 0,
                        'Timeline_Year' => null
                    ],
                    'project_layer' => [
                        'Data_ID' => null,
                        'Layer_Name' => $savedFileName,
                        'Attached_Date' => $dateTime,
                        'zindex' => 1,
                        'Default_View' => 0,
                        'Project_ID' => $project['project_id_number'],
                        'Attached_By' => $this->data['email'],
                        'layerGroup' => null,
                        'meta_id' => null,
                        'Modified_By' => null,
                        'Modified_Date' => null,
                        'show_metadata' => null,
                        'subGroupID' => null,
                        'subGroupName' => null,
                        'subLayerTitle' => null,
                        'Timeline_Year' => null
                    ]
                ];

                $kmlaicInsert = $this->DB->insertFileLayerData($this->data['fileType'], $kmlaicParams);

                if ($kmlaicInsert === false) {
                    $this->response['success'] = false;
                    $this->response['message'] = "File layer data not inserted to DB: {$keyOrFolder}\n";
                    
                    return $this->response;
                }

                if ($this->data['fileType'] === 'KML') {
                    $moveFileLayerInfo = [
                        'name' => $savedFileName,
                        'type' => $this->data['fileType'],
                        'project_id_number' => $project['project_id_number']
                    ];

                    $this->moveFileLayerToMainDir($moveFileLayerInfo);
                }

                $this->deleteDownloadedFiles();

                $this->response = [
                    'success' => true,
                    'message' => 'All files downloaded successfully.'
                ];

                return $this->response;
            }
        }  catch (Aws\Exception\AwsException $e) {
            $this->deleteDownloadedFiles();
            $this->response['message'] = "S3 Download failed: " . $e->getMessage();

            return $this->response;
        } 
         catch (\Exception $e) {
            $this->deleteDownloadedFiles();
            $this->response['message'] = "Download failed: " . $e->getMessage();

            return $this->response;
        }
    }

    /**
     * Delete downloaded files from local storage
     * 
     * @return void
     */
    private function deleteDownloadedFiles() 
    {
        $shpDir = __DIR__ . '/../Data/S3_FILES/Geoserver/Shapefile/*';
        $aicDir = __DIR__ . '/../Data/S3_FILES/Geoserver/AIC/*';
        $kmlDir = __DIR__ . '/../Data/S3_FILES/KML/*';

        exec('rm -rf ' . escapeshellarg($shpDir));
        exec('rm -rf ' . escapeshellarg($aicDir));
        exec('rm -rf ' . escapeshellarg($kmlDir));
    }

    /**
     * Delete All S3 File Layers
     */
    public function deleteAllS3FileLayers() {
        $kmlPaths = $this->DB->getAllKmlPaths();

        if (!empty($kmlPaths)) {
            foreach ($kmlPaths as $kmlPath) {
                if (file_exists($kmlPath)) {
                    unlink($kmlPath);
                }
            }
        }

        $this->deleteDownloadedFiles();
        $this->DB->deleteS3FileLayersData();
    }

    /**
     * Send files to Geoserver
     * 
     * @param array $geoFileLayers
     * 
     * @return array
     */
    private function sendToGeoServer($geoFileLayers)
    {
        $response = [
            'success' => false,
            'message' => ''
        ];

        if (empty($geoFileLayers)) {
            $response['message'] = "File layers failed to upload.";
            
            return $response;
        }

        foreach ($geoFileLayers as $geoFile) {
            $geoFileLayerUpload = $this->uploadFilesToGeoserver($geoFile);

            // Return error response if upload failed
            if (!$geoFileLayerUpload['success']) {
                $response['message'] = "Upload failed: " . $geoFileLayerUpload['message'];
                
                return $response;
            }
        }

        $response['success'] = true;
        $response['message'] = "All files uploaded successfully.";

        return $response;
    }

    /**
     * Upload files to Geoserver
     * 
     * @param array $file
     * 
     * @return array
     */
    private function uploadFilesToGeoserver($file)
    {
        $domain = $_ENV['GEOSERVERDOMAIN'];
        $fileType = $file['fileType'];
        $filePath = $fileType === 'SHP' ? $file['filePath'] : $file['filePath'] . $file['fileName'];
        $url = $domain . "/JavaBridge/geodataupload/fileTransfer.php";
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //If the function curl_file_create exists
        if(function_exists('curl_file_create')){
            //Use the recommended way, creating a CURLFile object.
            $filePath = curl_file_create($filePath);
        } else{
            //Otherwise, do it the old way.
            //Get the canonicalized pathname of our file and prepend the @ character.
            $filePath = '@' . realpath($filePath);
            //Turn off SAFE UPLOAD so that it accepts files, starting with an @
            curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
        }

        $data = array(
            "pwd" => base64_encode ($_ENV['GEOSERVERPW']),
            "file" => $filePath,
            "fileName" => $file['fileName'],
            "projectId" => $file['projectId'],
            "fileType" => $fileType === 'SHP' ? 'Shapefile' : $fileType
        );

        if ($file['fileType'] === 'SHP') {
            $data['folderName'] = $file['folderName'];
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 1000); //timeout in seconds
        // curl_setopt($ch, CURLOPT_VERBOSE, true);
        
        curl_exec($ch);
        
        if (curl_errno($ch)) {
            $this->response['message'] = "Upload failed: " . curl_error($ch);
            $this->response['url'] = $domain;
            $this->response['data'] = [
                'file' => $file['fileName']
            ];
            
            return $this->response;
        }
        curl_close($ch);

        $this->response['success'] = true;
        $this->response['message'] = "File uploaded successfully!";
        $this->response['data'] = [
            'file' => $data
        ];

        return $this->response;
    }

    /**
     * Move file layer to main directory
     * 
     * @param array $fileLayer
     * 
     * @return bool
     */
    protected function moveFileLayerToMainDir($fileLayer)
    {
        $source = '';
        $destination = '';

        if ($fileLayer['type'] === 'SHP') {
            $source = __DIR__ . '/../Data/S3_FILES/Geoserver/Shapefile/';
            $destination = __DIR__ . '/../Data/Geoserver/Shapefile/';
        }

        if ($fileLayer['type'] === 'AIC') {
            $source = __DIR__ . '/../Data/S3_FILES/Geoserver/AIC/';
            $destination = __DIR__ . '/../Data/Geoserver/AIC/';
        }

        if ($fileLayer['type'] === 'KML') {
            $source = __DIR__ . '/../Data/S3_FILES/KML/';
            $destination = __DIR__ . '/../Data/KML/';
        }

        $flSourceDir = $source . '/' . $fileLayer['project_id_number'] . '/';
        $flDestinationDir = $destination . '/' . $fileLayer['project_id_number'] . '/';

        if (!is_dir($flDestinationDir)) {
            mkdir($flDestinationDir, 0777, true);
        }

        $flSource = $flSourceDir . $fileLayer['name'];
        $flDestination = $flDestinationDir . $fileLayer['name'];

        return !rename($flSource, $flDestination) ? false : true;
    }
}