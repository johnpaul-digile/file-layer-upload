<?php
require_once 'filelayer.class.php';
require_once 'db.class.php';

class ParamValidation {

    protected $data;
    protected $response = [
        'success' => false,
        'message' => ''
    ];
    protected $DB;

    public function __construct() {
        $this->data = $_POST;
        $this->DB = new DB();
    }

    public function validateDownload() {
        if (!isset($this->data['fileLayer'])) {
             $this->response['message'] = 'File name not provided.';

             return $this->response;
        }
        
        $allowedFileTypes = ['SHP', 'AIC', 'KML'];
        
        if (!(isset($this->data['fileType']) && in_array($this->data['fileType'], $allowedFileTypes))) {
            $this->response['message'] = 'Invalid file type.';

            return $this->response;
        }

        if (!isset($this->data['projectName'])) {
            $this->response['message'] = 'Project name not provided.';

            return $this->response;
        } else {
            $sql = "SELECT project_id_number, project_id, project_name, parent_project_id_number FROM projects WHERE project_name = ?;";
            $params = [$this->data['projectName']];
            $projRow = $this->DB->checkIfExists($sql, $params);

            $_POST['project'] = $projRow;

            if (empty($projRow)) {
                $this->response['message'] = 'Project does not exist.';

                return $this->response;
            }

            if (!isset($this->data['email'])) {
                $this->response['message'] = 'Email not provided.';

                return $this->response;
            } else {
                $sql = "SELECT COUNT(*) AS count FROM users INNER JOIN pro_usr_rel ON users.user_ID = pro_usr_rel.Usr_ID WHERE pro_usr_rel.Pro_ID = ? AND users.user_email = ? AND pro_usr_rel.Pro_Role IN ('Project Manager', 'Project Monitor');";
                $params = [$projRow['project_id_number'], $this->data['email']];
                $userRow = $this->DB->checkIfExists($sql, $params);

                if (empty($userRow)) {
                    $this->response['message'] = 'User does not have permission to download file.';

                    return $this->response;
                }
            }
        }

        $this->response['success'] = true;
        $this->response['message'] = 'Parameters validated.';

        return $this->response;
    }
}

$validate = new ParamValidation();


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'message' => 'Invalid request method.',
        'success' => false
    ]);
    exit;
}

if (!isset($_POST['op'])) {
    echo json_encode([
        'message' => 'Invalid request.',
        'success' => false
    ]);
    exit;
}

switch ($_POST['op']) {
    case 'download':
        $validation = $validate->validateDownload();

        if (!$validation['success']) {
            echo json_encode($validation);
            exit;
        }

        $fileLayerClass = new FileLayer($_POST);
        $response = $fileLayerClass->download();

        echo json_encode($response);
        break;
    case 'delete-s3-file-layers':
        $fileLayerClass = new FileLayer($_POST);
        $fileLayerClass->deleteAllS3FileLayers();
        
        echo json_encode([
            'message' => 'Deleted successfully.',
            'success' => true
        ]);
        break;
    default:
        echo json_encode([
            'message' => 'Invalid request.',
            'success' => false
        ]);
        break;
}

