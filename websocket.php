<?php

require __DIR__ . '/vendor/autoload.php';

// Simple database connection for WebSocket server
function getDatabaseConnection() {
    // Use default values since .env parsing is failing
    $host = 'localhost';
    $database = 'blog_db';
    $username = 'root';
    $password = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        echo "Database connection failed: " . $e->getMessage() . "\n";
        return null;
    }
}

use React\EventLoop\Factory;
use React\Socket\Server as SocketServer;
use React\Http\Response;
use React\Http\Server as HttpServer;
use Psr\Http\Message\ServerRequestInterface;

class SimpleWebSocketServer {
    protected $clients = [];
    protected $users = [];
    protected $onlineUsers = []; // Track online users by ID
    protected $loop;
    protected $socket;

    public function __construct() {
        $this->loop = Factory::create();
        $this->socket = new SocketServer($this->loop);
        $this->socket->listen(8080, '0.0.0.0');

        $this->socket->on('connection', function ($conn) {
            echo "New connection! ({$conn->getRemoteAddress()})\n";
            $this->clients[] = $conn;

            $conn->on('data', function ($data) use ($conn) {
                $this->handleData($conn, $data);
            });

            $conn->on('close', function () use ($conn) {
                $this->onClose($conn);
            });
        });
    }

    protected function handleData($conn, $data) {
        // Check if this is a WebSocket handshake
        if (strpos($data, 'GET / HTTP/1.1') === 0 && strpos($data, 'Upgrade: websocket') !== false) {
            $this->handleWebSocketHandshake($conn, $data);
        } else {
            // This is WebSocket frame data
            $this->handleWebSocketFrame($conn, $data);
        }
    }

    protected function handleWebSocketHandshake($conn, $data) {
        // Extract Sec-WebSocket-Key
        if (preg_match('/Sec-WebSocket-Key: (.+)/', $data, $matches)) {
            $key = trim($matches[1]);
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

            $response = "HTTP/1.1 101 Switching Protocols\r\n";
            $response .= "Upgrade: websocket\r\n";
            $response .= "Connection: Upgrade\r\n";
            $response .= "Sec-WebSocket-Accept: $accept\r\n";
            $response .= "\r\n";

            $conn->write($response);
            echo "WebSocket handshake completed\n";
        }
    }

    protected function handleWebSocketFrame($conn, $data) {
        // Decode WebSocket frame (simplified implementation)
        if (strlen($data) < 2) return;

        $payloadLength = ord($data[1]) & 127;
        $maskStart = 2;

        if ($payloadLength == 126) {
            $maskStart = 4;
        } elseif ($payloadLength == 127) {
            $maskStart = 10;
        }

        $mask = substr($data, $maskStart, 4);
        $payload = substr($data, $maskStart + 4, $payloadLength);

        // Unmask the payload
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }

        echo "Received WebSocket message: " . $unmasked . "\n";

        $decodedData = json_decode($unmasked, true);

        if ($decodedData === null) {
            echo "JSON decode failed for WebSocket message: " . $unmasked . "\n";
            return;
        }

        echo "Decoded WebSocket data: " . json_encode($decodedData) . "\n";

        if (isset($decodedData['type'])) {
            if ($decodedData['type'] === 'register') {
                $userId = $decodedData['user_id'];
                $this->users[spl_object_hash($conn)] = $userId;

                // Track user as online when they connect
                $this->onlineUsers[$userId] = true;

                echo "✅ User {$userId} registered via WebSocket and marked as ONLINE\n";
                echo "👥 Online users: " . implode(', ', array_keys($this->onlineUsers)) . "\n";

                // Broadcast individual user status
                $this->broadcastUserStatus($userId, 'online');

                // Also broadcast the complete online status list
                $this->broadcastOnlineUsersList();
            } elseif ($decodedData['type'] === 'message') {
                $this->saveMessage($decodedData);
                $this->broadcastMessage($conn, $decodedData);
            }
        } else {
            echo "No 'type' field in WebSocket message\n";
        }
    }


    protected function saveMessage($data) {
        try {
            $pdo = getDatabaseConnection();
            if (!$pdo) {
                echo "Database connection not available\n";
                return;
            }

            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");

            $stmt->execute([
                $data['sender_id'],
                $data['receiver_id'],
                $data['message']
            ]);

            echo "Message saved to database: {$data['sender_id']} -> {$data['receiver_id']}: {$data['message']}\n";
        } catch (Exception $e) {
            echo "Error saving message to database: " . $e->getMessage() . "\n";
        }
    }

    protected function broadcastMessage($conn, $data) {
        $senderId = $this->users[spl_object_hash($conn)] ?? null;
        $receiverId = $data['receiver_id'];

        echo "Broadcasting message from {$senderId} to {$receiverId}\n";

        $messageFrame = $this->encodeWebSocketFrame(json_encode([
            'type' => 'message',
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message' => $data['message'],
            'timestamp' => date('H:i:s')
        ]));

        foreach ($this->clients as $client) {
            $clientUserId = $this->users[spl_object_hash($client)] ?? null;

            if ($clientUserId == $receiverId || $clientUserId == $senderId) {
                $client->write($messageFrame);
                echo "Message sent to client (user: {$clientUserId})\n";
            }
        }
    }

    protected function broadcastUserStatus($userId, $status) {
        echo "📡 Broadcasting user status: {$userId} is {$status}\n";
        echo "👥 Total clients connected: " . count($this->clients) . "\n";

        $statusMessage = [
            'type' => 'user_status',
            'user_id' => $userId,
            'status' => $status,
            'timestamp' => date('H:i:s')
        ];

        echo "📨 Status message: " . json_encode($statusMessage) . "\n";

        $statusFrame = $this->encodeWebSocketFrame(json_encode($statusMessage));

        $sentCount = 0;
        foreach ($this->clients as $client) {
            $client->write($statusFrame);
            $sentCount++;
            echo "✅ Status update sent to client {$sentCount}\n";
        }

        echo "📊 Status broadcast completed. Sent to {$sentCount} clients.\n";
    }

    protected function broadcastOnlineUsersList() {
        echo "📋 Broadcasting complete online users list to ALL clients\n";

        $onlineUsersList = [
            'type' => 'online_users_list',
            'online_users' => array_keys($this->onlineUsers),
            'timestamp' => date('H:i:s')
        ];

        echo "📨 Online users list: " . json_encode($onlineUsersList) . "\n";

        $listFrame = $this->encodeWebSocketFrame(json_encode($onlineUsersList));

        $sentCount = 0;
        foreach ($this->clients as $client) {
            $client->write($listFrame);
            $sentCount++;
        }

        echo "📊 Online users list broadcast completed. Sent to {$sentCount} clients.\n";
    }

    protected function sendOnlineUsersListToClient($conn) {
        echo "📋 Sending current online users list to newly connected client\n";

        $onlineUsersList = [
            'type' => 'online_users_list',
            'online_users' => array_keys($this->onlineUsers),
            'timestamp' => date('H:i:s')
        ];

        echo "📨 Sending to new client: " . json_encode($onlineUsersList) . "\n";

        $listFrame = $this->encodeWebSocketFrame(json_encode($onlineUsersList));
        $conn->write($listFrame);

        echo "✅ Online users list sent to new client\n";
    }

    protected function encodeWebSocketFrame($payload) {
        $frame = chr(129); // Text frame

        $length = strlen($payload);
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('N', 0) . pack('N', $length);
        }

        $frame .= $payload;
        return $frame;
    }

    public function onClose($conn) {
        $userId = $this->users[spl_object_hash($conn)] ?? null;
        echo "🔌 Connection closed for user: " . ($userId ?? 'unknown') . "\n";

        $key = array_search($conn, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
            unset($this->users[spl_object_hash($conn)]);
            echo "🗑️ Cleaned up connection data\n";
        }

        if ($userId) {
            // Remove from online users
            unset($this->onlineUsers[$userId]);

            echo "📡 Broadcasting offline status for user {$userId}\n";
            $this->broadcastUserStatus($userId, 'offline');

            // Broadcast updated online users list
            $this->broadcastOnlineUsersList();
        } else {
            echo "⚠️ No user ID found for disconnected connection\n";
        }

        echo "📊 Remaining connections: " . count($this->clients) . "\n";
        echo "👥 Online users: " . implode(', ', array_keys($this->onlineUsers)) . "\n";
    }

    public function run() {
        echo "WebSocket server started on port 8080\n";
        $this->loop->run();
    }
}

// Test database connection
$db = getDatabaseConnection();
if ($db) {
    echo "Database connection successful\n";
} else {
    echo "Database connection failed\n";
}

$server = new SimpleWebSocketServer();
$server->run();