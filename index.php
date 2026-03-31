<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,PATCH,DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    http_response_code(200);
    exit();
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'restful_api');
define('DB_USER', 'root');
define('DB_PASS', '');

define('TABLE_USERS', 'users');

function getDBConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
        exit();
    }
}

function initDatabase() {
    $pdo = getDBConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS " . TABLE_USERS . " (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        eta INT,
        attivo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    $pdo->exec($sql);
    
    // Inserisci dati di esempio se la tabella è vuota
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM " . TABLE_USERS);
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        $sampleData = [
            ['nome' => 'Mario Rossi', 'email' => 'mario@example.com', 'eta' => 30, 'attivo' => true],
            ['nome' => 'Laura Bianchi', 'email' => 'laura@example.com', 'eta' => 25, 'attivo' => true],
            ['nome' => 'Giuseppe Verdi', 'email' => 'giuseppe@example.com', 'eta' => 35, 'attivo' => false]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO " . TABLE_USERS . " (nome, email, eta, attivo) VALUES (?, ?, ?, ?)");
        foreach ($sampleData as $user) {
            $stmt->execute([$user['nome'], $user['email'], $user['eta'], $user['attivo']]);
        }
    }
}

$metodo = $_SERVER["REQUEST_METHOD"];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);

if ($metodo == 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $metodo = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
}

$ct = $_SERVER["CONTENT_TYPE"] ?? 'application/json';
$type = explode("/", $ct);

$retct = $_SERVER["HTTP_ACCEPT"] ?? 'application/json';
$ret = explode("/", $retct);

$id = null;
for ($i = 0; $i < count($uri); $i++) {
    if ($uri[$i] == 'users' && isset($uri[$i+1]) && is_numeric($uri[$i+1])) {
        $id = (int)$uri[$i+1];
        break;
    }
}

$body = file_get_contents('php://input');

$requestData = [];
if (!empty($body)) {
    if (isset($type[1]) && $type[1] == "json") {
        $requestData = json_decode($body, true) ?? [];
    } elseif (isset($type[1]) && $type[1] == "xml") {
        $xml = simplexml_load_string($body);
        $json = json_encode($xml);
        $requestData = json_decode($json, true) ?? [];
    } elseif (isset($type[1]) && $type[1] == "x-www-form-urlencoded") {
        parse_str($body, $requestData);
    }
}

initDatabase();
$pdo = getDBConnection();

$response = [];
$statusCode = 200;

switch ($metodo) {
    case 'GET':
        if ($id !== null) {
            $stmt = $pdo->prepare("SELECT * FROM " . TABLE_USERS . " WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if ($user) {
                $response = $user;
                $statusCode = 200;
            } else {
                $response = ['error' => 'Utente non trovato'];
                $statusCode = 404;
            }
        } else {
            $stmt = $pdo->query("SELECT * FROM " . TABLE_USERS . " ORDER BY id");
            $users = $stmt->fetchAll();
            $response = $users;
            $statusCode = 200;
        }
        break;
        
    case 'POST':
        if (empty($requestData)) {
            $response = ['error' => 'Dati non validi o mancanti'];
            $statusCode = 400;
            break;
        }
        
        if (empty($requestData['nome']) || empty($requestData['email'])) {
            $response = ['error' => 'I campi nome ed email sono obbligatori'];
            $statusCode = 400;
            break;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO " . TABLE_USERS . " (nome, email, eta, attivo) 
                VALUES (?, ?, ?, ?)
            ");
            
            $eta = isset($requestData['eta']) ? (int)$requestData['eta'] : null;
            $attivo = isset($requestData['attivo']) ? (bool)$requestData['attivo'] : true;
            
            $stmt->execute([
                $requestData['nome'],
                $requestData['email'],
                $eta,
                $attivo
            ]);
            
            $newId = $pdo->lastInsertId();
            
            $response = [
                'message' => 'Utente creato con successo',
                'item' => [
                    'id' => (int)$newId,
                    'nome' => $requestData['nome'],
                    'email' => $requestData['email'],
                    'eta' => $eta,
                    'attivo' => $attivo
                ]
            ];
            $statusCode = 201;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                $response = ['error' => 'Email già esistente'];
                $statusCode = 409;
            } else {
                $response = ['error' => 'Errore nel database: ' . $e->getMessage()];
                $statusCode = 500;
            }
        }
        break;
        
    case 'PUT':
        if ($id === null) {
            $response = ['error' => 'ID richiesto per la modifica'];
            $statusCode = 400;
            break;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM " . TABLE_USERS . " WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $response = ['error' => 'Utente non trovato'];
            $statusCode = 404;
            break;
        }
        
        if (empty($requestData['nome']) || empty($requestData['email'])) {
            $response = ['error' => 'I campi nome ed email sono obbligatori'];
            $statusCode = 400;
            break;
        }
        
        try {
            $eta = isset($requestData['eta']) ? (int)$requestData['eta'] : null;
            $attivo = isset($requestData['attivo']) ? (bool)$requestData['attivo'] : true;
            
            $stmt = $pdo->prepare("
                UPDATE " . TABLE_USERS . " 
                SET nome = ?, email = ?, eta = ?, attivo = ? 
                WHERE id = ?
            ");
            
            $stmt->execute([
                $requestData['nome'],
                $requestData['email'],
                $eta,
                $attivo,
                $id
            ]);
            
            $response = [
                'message' => 'Utente aggiornato con successo',
                'item' => [
                    'id' => $id,
                    'nome' => $requestData['nome'],
                    'email' => $requestData['email'],
                    'eta' => $eta,
                    'attivo' => $attivo
                ]
            ];
            $statusCode = 200;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $response = ['error' => 'Email già esistente'];
                $statusCode = 409;
            } else {
                $response = ['error' => 'Errore nel database: ' . $e->getMessage()];
                $statusCode = 500;
            }
        }
        break;
        
    case 'PATCH':
        if ($id === null) {
            $response = ['error' => 'ID richiesto per la modifica'];
            $statusCode = 400;
            break;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM " . TABLE_USERS . " WHERE id = ?");
        $stmt->execute([$id]);
        $existingUser = $stmt->fetch();
        
        if (!$existingUser) {
            $response = ['error' => 'Utente non trovato'];
            $statusCode = 404;
            break;
        }
        
        $updateFields = [];
        $updateValues = [];
        
        if (isset($requestData['nome'])) {
            $updateFields[] = "nome = ?";
            $updateValues[] = $requestData['nome'];
        }
        if (isset($requestData['email'])) {
            $updateFields[] = "email = ?";
            $updateValues[] = $requestData['email'];
        }
        if (isset($requestData['eta'])) {
            $updateFields[] = "eta = ?";
            $updateValues[] = (int)$requestData['eta'];
        }
        if (isset($requestData['attivo'])) {
            $updateFields[] = "attivo = ?";
            $updateValues[] = (bool)$requestData['attivo'];
        }
        
        if (empty($updateFields)) {
            $response = ['error' => 'Nessun campo da aggiornare'];
            $statusCode = 400;
            break;
        }
        
        try {
            $updateValues[] = $id;
            $sql = "UPDATE " . TABLE_USERS . " SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
            
            $stmt = $pdo->prepare("SELECT * FROM " . TABLE_USERS . " WHERE id = ?");
            $stmt->execute([$id]);
            $updatedUser = $stmt->fetch();
            
            $response = [
                'message' => 'Utente aggiornato con successo',
                'item' => $updatedUser
            ];
            $statusCode = 200;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $response = ['error' => 'Email già esistente'];
                $statusCode = 409;
            } else {
                $response = ['error' => 'Errore nel database: ' . $e->getMessage()];
                $statusCode = 500;
            }
        }
        break;
        
    case 'DELETE':
        if ($id === null) {
            $response = ['error' => 'ID richiesto per la cancellazione'];
            $statusCode = 400;
            break;
        }
        
        $stmt = $pdo->prepare("SELECT id FROM " . TABLE_USERS . " WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("DELETE FROM " . TABLE_USERS . " WHERE id = ?");
            $stmt->execute([$id]);
            
            $response = ['message' => 'Utente eliminato con successo'];
            $statusCode = 200;
        } else {
            $response = ['error' => 'Utente non trovato'];
            $statusCode = 404;
        }
        break;
        
    default:
        $response = ['error' => 'Metodo non supportato'];
        $statusCode = 405;
}

http_response_code($statusCode);

header("Content-Type: " . $retct);

if (isset($ret[1]) && $ret[1] == "json") {
    echo json_encode($response, JSON_PRETTY_PRINT);
} elseif (isset($ret[1]) && $ret[1] == "xml") {
    $xml = new SimpleXMLElement('<?xml version="1.0"?><response/>');
    array_to_xml($response, $xml);
    echo $xml->asXML();
} else {
    header("Content-Type: application/json");
    echo json_encode($response, JSON_PRETTY_PRINT);
}

function array_to_xml($data, &$xml) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (is_numeric($key)) {
                $key = 'item';
            }
            $subnode = $xml->addChild($key);
            array_to_xml($value, $subnode);
        } else {
            $xml->addChild(str_replace(' ', '_', $key), htmlspecialchars((string)$value));
        }
    }
}
?>
