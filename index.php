<?php
// voicechat_wasmer.php
session_start();

// ==================== DATABASE CONFIG ====================
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'voicechat';

// Create connection
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database and tables if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS $DB_NAME");
$conn->select_db($DB_NAME);

// Create tables
$tables = [
    "CREATE TABLE IF NOT EXISTS rooms (
        id VARCHAR(32) PRIMARY KEY,
        host_name VARCHAR(100) DEFAULT 'Host',
        room_name VARCHAR(100) DEFAULT 'Voice Room',
        max_participants INT DEFAULT 2,
        current_participants INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        requires_approval BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "CREATE TABLE IF NOT EXISTS participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_token VARCHAR(32),
        participant_id VARCHAR(50) UNIQUE,
        user_name VARCHAR(100) DEFAULT 'Guest',
        user_agent TEXT,
        ip_address VARCHAR(45),
        device_name VARCHAR(100),
        status ENUM('waiting','approved','rejected','active','left') DEFAULT 'waiting',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_at TIMESTAMP NULL,
        INDEX idx_room_token (room_token),
        INDEX idx_status (status)
    )",
    
    "CREATE TABLE IF NOT EXISTS webrtc_signals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_token VARCHAR(32),
        from_participant VARCHAR(50),
        to_participant VARCHAR(50),
        signal_type ENUM('offer','answer','candidate','control'),
        signal_data TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_room (room_token)
    )"
];

foreach ($tables as $sql) {
    $conn->query($sql);
}

// ==================== HELPER FUNCTIONS ====================
function generateToken($length = 32) {
    return bin2hex(random_bytes($length/2));
}

function getDeviceInfo($ua) {
    $device = "Unknown";
    if (stripos($ua, 'iPhone') !== false) $device = "iPhone";
    elseif (stripos($ua, 'Android') !== false) $device = "Android Phone";
    elseif (stripos($ua, 'Windows') !== false) $device = "Windows PC";
    elseif (stripos($ua, 'Mac') !== false) $device = "Mac";
    elseif (stripos($ua, 'Linux') !== false) $device = "Linux PC";
    
    if (stripos($ua, 'Mobile') !== false) $device .= " Mobile";
    return $device;
}

function getClientIP() {
    $ip = $_SERVER['HTTP_CLIENT_IP'] ?? 
          $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
          $_SERVER['REMOTE_ADDR'] ?? 
          '127.0.0.1';
    return $ip;
}

// ==================== API HANDLER ====================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    global $conn;
    
    switch ($_GET['api']) {
        case 'create_room':
            $token = generateToken(16);
            $host_name = $conn->real_escape_string($_POST['host_name'] ?? 'Host');
            $room_name = $conn->real_escape_string($_POST['room_name'] ?? 'Voice Chat');
            $max_participants = intval($_POST['max_participants'] ?? 2);
            
            $sql = "INSERT INTO rooms (id, host_name, room_name, max_participants) 
                    VALUES ('$token', '$host_name', '$room_name', $max_participants)";
            
            if ($conn->query($sql)) {
                $base_url = "http://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]";
                echo json_encode([
                    'success' => true,
                    'room_token' => $token,
                    'join_url' => "$base_url?join=$token",
                    'host_url' => "$base_url?host=$token",
                    'message' => 'Room created successfully!'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
            
        case 'join_room':
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            $user_name = $conn->real_escape_string($_POST['user_name'] ?? 'Guest');
            
            // Check room exists and is active
            $room = $conn->query("SELECT * FROM rooms WHERE id='$room_token' AND is_active=1")->fetch_assoc();
            if (!$room) {
                echo json_encode(['success' => false, 'error' => 'Room not found or inactive']);
                exit;
            }
            
            // Check capacity
            $current = $conn->query("SELECT COUNT(*) as cnt FROM participants WHERE room_token='$room_token' AND status IN ('approved','active')")->fetch_assoc()['cnt'];
            if ($current >= $room['max_participants']) {
                echo json_encode(['success' => false, 'error' => 'Room is full']);
                exit;
            }
            
            // Create participant
            $participant_id = 'p_' . generateToken(12);
            $device = getDeviceInfo($_SERVER['HTTP_USER_AGENT']);
            $ip = getClientIP();
            
            $sql = "INSERT INTO participants (room_token, participant_id, user_name, user_agent, ip_address, device_name, status) 
                    VALUES ('$room_token', '$participant_id', '$user_name', '{$_SERVER['HTTP_USER_AGENT']}', '$ip', '$device', 'waiting')";
            
            if ($conn->query($sql)) {
                $_SESSION['participant_id'] = $participant_id;
                $_SESSION['room_token'] = $room_token;
                $_SESSION['user_name'] = $user_name;
                
                echo json_encode([
                    'success' => true,
                    'participant_id' => $participant_id,
                    'requires_approval' => $room['requires_approval'],
                    'device' => $device
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to join room']);
            }
            exit;
            
        case 'get_pending_requests':
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            $requests = [];
            
            $sql = "SELECT * FROM participants 
                    WHERE room_token='$room_token' AND status='waiting' 
                    ORDER BY joined_at ASC";
            $result = $conn->query($sql);
            
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            
            echo json_encode(['success' => true, 'requests' => $requests]);
            exit;
            
        case 'approve_request':
            $participant_id = $conn->real_escape_string($_POST['participant_id'] ?? '');
            $approve = intval($_POST['approve'] ?? 1);
            
            $status = $approve ? 'approved' : 'rejected';
            $sql = "UPDATE participants SET status='$status', approved_at=NOW() 
                    WHERE participant_id='$participant_id'";
            
            if ($conn->query($sql)) {
                if ($approve) {
                    // Update room count
                    $room = $conn->query("SELECT room_token FROM participants WHERE participant_id='$participant_id'")->fetch_assoc();
                    if ($room) {
                        $conn->query("UPDATE rooms SET current_participants = current_participants + 1 WHERE id='{$room['room_token']}'");
                    }
                }
                echo json_encode(['success' => true, 'status' => $status]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
            
        case 'send_signal':
            // WebRTC signaling via database polling
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            $from_id = $conn->real_escape_string($_POST['from_id'] ?? '');
            $to_id = $conn->real_escape_string($_POST['to_id'] ?? 'all');
            $type = $conn->real_escape_string($_POST['type'] ?? '');
            $data = $conn->real_escape_string($_POST['data'] ?? '');
            
            $sql = "INSERT INTO webrtc_signals (room_token, from_participant, to_participant, signal_type, signal_data) 
                    VALUES ('$room_token', '$from_id', '$to_id', '$type', '$data')";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'signal_id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
            
        case 'get_signals':
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            $participant_id = $conn->real_escape_string($_POST['participant_id'] ?? '');
            $last_id = intval($_POST['last_id'] ?? 0);
            
            $sql = "SELECT * FROM webrtc_signals 
                    WHERE room_token='$room_token' 
                    AND (to_participant='$participant_id' OR to_participant='all')
                    AND id > $last_id 
                    ORDER BY id ASC";
            
            $result = $conn->query($sql);
            $signals = [];
            
            while ($row = $result->fetch_assoc()) {
                $signals[] = $row;
            }
            
            echo json_encode(['success' => true, 'signals' => $signals]);
            exit;
            
        case 'get_room_info':
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            
            $room = $conn->query("SELECT * FROM rooms WHERE id='$room_token'")->fetch_assoc();
            $participants = [];
            
            $result = $conn->query("SELECT * FROM participants WHERE room_token='$room_token' AND status IN ('approved','active')");
            while ($row = $result->fetch_assoc()) {
                $participants[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'room' => $room,
                'participants' => $participants
            ]);
            exit;
            
        case 'update_settings':
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            $max_participants = intval($_POST['max_participants'] ?? 2);
            $requires_approval = intval($_POST['requires_approval'] ?? 1);
            
            $sql = "UPDATE rooms SET max_participants=$max_participants, requires_approval=$requires_approval 
                    WHERE id='$room_token'";
            
            if ($conn->query($sql)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
            exit;
            
        case 'end_call':
            $participant_id = $conn->real_escape_string($_POST['participant_id'] ?? '');
            $room_token = $conn->real_escape_string($_POST['room_token'] ?? '');
            
            $conn->query("UPDATE participants SET status='left' WHERE participant_id='$participant_id'");
            $conn->query("UPDATE rooms SET current_participants = GREATEST(0, current_participants - 1) WHERE id='$room_token'");
            
            echo json_encode(['success' => true]);
            exit;
    }
}

// ==================== HTML INTERFACE ====================
$page = 'home';
if (isset($_GET['join'])) {
    $page = 'join';
    $room_token = $_GET['join'];
} elseif (isset($_GET['host'])) {
    $page = 'host';
    $room_token = $_GET['host'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎙️ Voice Chat P2P</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 2.5em; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        
        .card {
            padding: 30px;
            margin: 20px;
            background: #f8f9fa;
            border-radius: 15px;
            border: 1px solid #e9ecef;
        }
        
        .btn {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .btn-success { background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%); }
        .btn-danger { background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); }
        .btn-secondary { background: linear-gradient(135deg, #8e9eab 0%, #eef2f3 100%); color: #333; }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #495057;
        }
        input, select, textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #6a11cb;
        }
        
        .panel-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        @media (max-width: 768px) {
            .panel-grid { grid-template-columns: 1fr; }
        }
        
        .participant-list {
            max-height: 400px;
            overflow-y: auto;
        }
        .participant-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #6a11cb;
        }
        .participant-card.waiting { border-left-color: #ffc107; }
        .participant-card.approved { border-left-color: #28a745; }
        .participant-card.rejected { border-left-color: #dc3545; }
        
        .video-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .video-box {
            background: #212529;
            border-radius: 15px;
            padding: 20px;
            position: relative;
        }
        video {
            width: 100%;
            border-radius: 10px;
            background: black;
        }
        .video-label {
            position: absolute;
            top: 10px;
            left: 20px;
            color: white;
            background: rgba(0,0,0,0.5);
            padding: 5px 10px;
            border-radius: 5px;
        }
        
        .controls {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 30px 0;
        }
        .control-btn {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: none;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .control-btn:hover {
            transform: scale(1.1);
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 20px;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            z-index: 1000;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast.success { background: #28a745; }
        .toast.error { background: #dc3545; }
        .toast.warning { background: #ffc107; color: #333; }
        .toast.info { background: #17a2b8; }
        
        .loader {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #6a11cb;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 40px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .room-link {
            background: #e9ecef;
            padding: 15px;
            border-radius: 10px;
            font-family: monospace;
            word-break: break-all;
            margin: 20px 0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-waiting { background: #ffc107; color: #333; }
        .badge-approved { background: #28a745; color: white; }
        .badge-active { background: #17a2b8; color: white; }
        .badge-rejected { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎙️ Voice Chat P2P</h1>
            <p>Private voice calls with host approval system</p>
        </div>
        
        <?php if ($page == 'home'): ?>
        <!-- HOME PAGE -->
        <div class="card">
            <h2>Create New Room</h2>
            <div class="form-group">
                <label>Your Name</label>
                <input type="text" id="hostName" placeholder="Enter your name" value="Host">
            </div>
            <div class="form-group">
                <label>Room Name</label>
                <input type="text" id="roomName" placeholder="Room name" value="My Voice Room">
            </div>
            <div class="form-group">
                <label>Max Participants</label>
                <select id="maxParticipants">
                    <option value="2">2 (1-on-1)</option>
                    <option value="4">4</option>
                    <option value="8">8</option>
                    <option value="16">16</option>
                </select>
            </div>
            <button class="btn" onclick="createRoom()">
                🎯 Create Room
            </button>
            
            <div id="roomResult" style="display: none; margin-top: 30px;">
                <h3>✅ Room Created Successfully!</h3>
                <p>Share this link with friends:</p>
                <div class="room-link" id="roomLink"></div>
                <div class="form-group">
                    <button class="btn" onclick="copyToClipboard('roomLink')">📋 Copy Link</button>
                    <a href="#" id="hostLink" class="btn btn-success">🎛️ Go to Host Panel</a>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>Join Existing Room</h2>
            <div class="form-group">
                <label>Room Token/Link</label>
                <input type="text" id="joinToken" placeholder="Enter room token from link">
            </div>
            <div class="form-group">
                <label>Your Name</label>
                <input type="text" id="guestName" placeholder="Your name" value="Guest">
            </div>
            <button class="btn" onclick="joinRoom()">
                🚪 Join Room
            </button>
        </div>
        
        <?php elseif ($page == 'join'): ?>
        <!-- JOIN ROOM PAGE -->
        <div class="card">
            <h2>Joining Room: <?php echo htmlspecialchars($room_token); ?></h2>
            <div id="joinStatus">
                <p>Connecting to room...</p>
                <div class="loader"></div>
                <div id="statusMessage"></div>
            </div>
            
            <div id="callInterface" style="display: none;">
                <h3>🎧 Voice Call Active</h3>
                <div class="video-container">
                    <div class="video-box">
                        <div class="video-label">You</div>
                        <audio id="localAudio" autoplay muted controls></audio>
                        <div style="color: white; margin-top: 10px; text-align: center;">
                            <span id="localStatus">🟢 Connected</span>
                        </div>
                    </div>
                    <div class="video-box">
                        <div class="video-label">Remote</div>
                        <audio id="remoteAudio" autoplay controls></audio>
                        <div style="color: white; margin-top: 10px; text-align: center;">
                            <span id="remoteStatus">⏳ Waiting for connection...</span>
                        </div>
                    </div>
                </div>
                
                <div class="controls">
                    <button class="control-btn" id="muteBtn" onclick="toggleMute()" style="background: #4CAF50;">🎤</button>
                    <button class="control-btn btn-danger" onclick="endCall()" style="background: #f44336;">📞</button>
                    <button class="control-btn" id="speakerBtn" onclick="toggleSpeaker()" style="background: #2196F3;">🔊</button>
                </div>
                
                <div id="chatBox" style="margin-top: 20px;">
                    <h4>💬 Quick Chat</h4>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn-secondary" onclick="sendQuickMessage('👍')">👍</button>
                        <button class="btn-secondary" onclick="sendQuickMessage('👎')">👎</button>
                        <button class="btn-secondary" onclick="sendQuickMessage('🔊 Volume up')">🔊</button>
                        <button class="btn-secondary" onclick="sendQuickMessage('🔈 Volume down')">🔈</button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($page == 'host'): ?>
        <!-- HOST PANEL -->
        <div class="card">
            <h2>🎛️ Host Control Panel</h2>
            <div class="panel-grid">
                <div>
                    <div class="form-group">
                        <h3>Room Information</h3>
                        <div id="roomInfo">
                            <p>Loading room info...</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <h3>⏳ Approval Requests</h3>
                        <div id="approvalRequests" class="participant-list">
                            <p>No pending requests</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <h3>👥 Active Participants</h3>
                        <div id="activeParticipants" class="participant-list">
                            <p>No active participants yet</p>
                        </div>
                    </div>
                </div>
                
                <div>
                    <div class="card" style="padding: 20px; background: white;">
                        <h3>⚙️ Room Settings</h3>
                        <div class="form-group">
                            <label>Max Participants</label>
                            <select id="settingsMaxParticipants">
                                <option value="2">2</option>
                                <option value="4">4</option>
                                <option value="8">8</option>
                                <option value="16">16</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="settingsRequireApproval" checked>
                                Require Approval for Join
                            </label>
                        </div>
                        <button class="btn" onclick="updateRoomSettings()" style="width: 100%;">
                            💾 Save Settings
                        </button>
                    </div>
                    
                    <div class="card" style="padding: 20px; background: white; margin-top: 20px;">
                        <h3>🔗 Room Link</h3>
                        <div class="room-link" id="roomLinkDisplay">
                            Loading...
                        </div>
                        <button class="btn" onclick="copyRoomLink()" style="width: 100%; margin-top: 10px;">
                            📋 Copy Link
                        </button>
                    </div>
                    
                    <div class="card" style="padding: 20px; background: white; margin-top: 20px;">
                        <h3>⚠️ Room Controls</h3>
                        <button class="btn btn-secondary" onclick="lockRoom()" style="width: 100%; margin-bottom: 10px;">
                            🔒 Lock Room (No New Joins)
                        </button>
                        <button class="btn btn-danger" onclick="endRoomForAll()" style="width: 100%;">
                            ⏹️ End Room for Everyone
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Toast Notification -->
    <div id="toast" class="toast" style="display: none;"></div>
    
    <script>
        // ==================== GLOBAL VARIABLES ====================
        let roomToken = '<?php echo $room_token ?? ""; ?>';
        let participantId = '<?php echo $_SESSION["participant_id"] ?? ""; ?>';
        let userName = '<?php echo $_SESSION["user_name"] ?? ""; ?>';
        let isHost = <?php echo isset($_GET['host']) ? 'true' : 'false'; ?>;
        
        let localStream = null;
        let peerConnection = null;
        let pollingInterval = null;
        let signalPolling = null;
        
        // ==================== HELPER FUNCTIONS ====================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 4000);
        }
        
        function copyToClipboard(elementId) {
            const element = document.getElementById(elementId);
            const text = element.textContent || element.value;
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied to clipboard!', 'success');
            });
        }
        
        function copyRoomLink() {
            const link = document.getElementById('roomLinkDisplay').textContent;
            navigator.clipboard.writeText(link).then(() => {
                showToast('Room link copied!', 'success');
            });
        }
        
        // ==================== ROOM MANAGEMENT ====================
        async function createRoom() {
            const hostName = document.getElementById('hostName').value;
            const roomName = document.getElementById('roomName').value;
            const maxParticipants = document.getElementById('maxParticipants').value;
            
            const formData = new FormData();
            formData.append('host_name', hostName);
            formData.append('room_name', roomName);
            formData.append('max_participants', maxParticipants);
            
            try {
                const response = await fetch('?api=create_room', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('roomLink').textContent = result.join_url;
                    document.getElementById('hostLink').href = result.host_url;
                    document.getElementById('roomResult').style.display = 'block';
                    showToast('Room created successfully!', 'success');
                } else {
                    showToast('Error: ' + result.error, 'error');
                }
            } catch (error) {
                showToast('Network error: ' + error.message, 'error');
            }
        }
        
        async function joinRoom() {
            const token = document.getElementById('joinToken').value;
            const name = document.getElementById('guestName').value;
            
            if (!token) {
                showToast('Please enter room token', 'warning');
                return;
            }
            
            window.location.href = `?join=${token}&name=${encodeURIComponent(name)}`;
        }
        
        // ==================== WEBRTC FUNCTIONS ====================
        async function initializeWebRTC() {
            try {
                // Get microphone access
                localStream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                        channelCount: 1,
                        sampleRate: 24000
                    },
                    video: false
                });
                
                // Set up local audio element
                const localAudio = document.getElementById('localAudio');
                if (localAudio) {
                    localAudio.srcObject = localStream;
                }
                
                // Create peer connection configuration
                const config = {
                    iceServers: [
                        { urls: 'stun:stun.l.google.com:19302' },
                        { urls: 'stun:stun1.l.google.com:19302' },
                        { urls: 'stun:stun2.l.google.com:19302' }
                    ],
                    iceTransportPolicy: 'all',
                    rtcpMuxPolicy: 'require'
                };
                
                // Create peer connection
                peerConnection = new RTCPeerConnection(config);
                
                // Add local stream tracks
                localStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localStream);
                });
                
                // Handle incoming audio
                peerConnection.ontrack = (event) => {
                    const remoteAudio = document.getElementById('remoteAudio');
                    if (remoteAudio && event.streams[0]) {
                        remoteAudio.srcObject = event.streams[0];
                        document.getElementById('remoteStatus').textContent = '🟢 Connected';
                    }
                };
                
                // Handle ICE candidates
                peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        sendSignal('candidate', JSON.stringify(event.candidate));
                    }
                };
                
                // Handle connection state
                peerConnection.onconnectionstatechange = () => {
                    console.log('Connection state:', peerConnection.connectionState);
                    if (peerConnection.connectionState === 'connected') {
                        showToast('Connected to peer!', 'success');
                    }
                };
                
                // Start polling for signals
                startSignalPolling();
                
                // If we're joining (not host), create an offer
                if (!isHost && participantId) {
                    setTimeout(() => createOffer(), 1000);
                }
                
            } catch (error) {
                console.error('WebRTC Error:', error);
                showToast('Error accessing microphone: ' + error.message, 'error');
            }
        }
        
        async function createOffer() {
            try {
                const offer = await peerConnection.createOffer({
                    offerToReceiveAudio: true
                });
                
                await peerConnection.setLocalDescription(offer);
                
                // Send offer via database
                await sendSignal('offer', JSON.stringify(offer));
                
                console.log('Offer created and sent');
            } catch (error) {
                console.error('Error creating offer:', error);
            }
        }
        
        async function handleOffer(offerData) {
            try {
                const offer = JSON.parse(offerData);
                await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
                
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);
                
                // Send answer back
                await sendSignal('answer', JSON.stringify(answer));
            } catch (error) {
                console.error('Error handling offer:', error);
            }
        }
        
        async function handleAnswer(answerData) {
            try {
                const answer = JSON.parse(answerData);
                await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
            } catch (error) {
                console.error('Error handling answer:', error);
            }
        }
        
        async function handleCandidate(candidateData) {
            try {
                const candidate = JSON.parse(candidateData);
                await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
            } catch (error) {
                console.error('Error adding ICE candidate:', error);
            }
        }
        
        // ==================== SIGNALING (Database Polling) ====================
        async function sendSignal(type, data, to = 'all') {
            const formData = new FormData();
            formData.append('room_token', roomToken);
            formData.append('from_id', participantId);
            formData.append('to_id', to);
            formData.append('type', type);
            formData.append('data', data);
            
            try {
                await fetch('?api=send_signal', {
                    method: 'POST',
                    body: formData
                });
            } catch (error) {
                console.error('Error sending signal:', error);
            }
        }
        
        async function pollSignals() {
            const formData = new FormData();
            formData.append('room_token', roomToken);
            formData.append('participant_id', participantId);
            formData.append('last_id', window.lastSignalId || 0);
            
            try {
                const response = await fetch('?api=get_signals', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.signals.length > 0) {
                    result.signals.forEach(signal => {
                        window.lastSignalId = Math.max(window.lastSignalId || 0, signal.id);
                        
                        // Don't process our own signals
                        if (signal.from_participant === participantId) return;
                        
                        switch (signal.signal_type) {
                            case 'offer':
                                handleOffer(signal.signal_data);
                                break;
                            case 'answer':
                                handleAnswer(signal.signal_data);
                                break;
                            case 'candidate':
                                handleCandidate(signal.signal_data);
                                break;
                            case 'control':
                                handleControlSignal(signal.signal_data);
                                break;
                        }
                    });
                }
            } catch (error) {
                console.error('Error polling signals:', error);
            }
        }
        
        function startSignalPolling() {
            signalPolling = setInterval(pollSignals, 1000); // Poll every second
        }
        
        function handleControlSignal(data) {
            try {
                const control = JSON.parse(data);
                showToast(`Control: ${control.message}`, 'info');
            } catch (error) {
                console.error('Error handling control signal:', error);
            }
        }
        
        // ==================== CALL CONTROLS ====================
        function toggleMute() {
            if (localStream) {
                const audioTrack = localStream.getAudioTracks()[0];
                if (audioTrack) {
                    audioTrack.enabled = !audioTrack.enabled;
                    const btn = document.getElementById('muteBtn');
                    btn.textContent = audioTrack.enabled ? '🎤' : '🔇';
                    btn.style.background = audioTrack.enabled ? '#4CAF50' : '#ff9800';
                    showToast(audioTrack.enabled ? 'Microphone unmuted' : 'Microphone muted', 'info');
                }
            }
        }
        
        function toggleSpeaker() {
            const remoteAudio = document.getElementById('remoteAudio');
            if (remoteAudio) {
                remoteAudio.muted = !remoteAudio.muted;
                const btn = document.getElementById('speakerBtn');
                btn.textContent = remoteAudio.muted ? '🔇' : '🔊';
                btn.style.background = remoteAudio.muted ? '#ff9800' : '#2196F3';
                showToast(remoteAudio.muted ? 'Speaker muted' : 'Speaker active', 'info');
            }
        }
        
        async function endCall() {
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
            }
            
            if (peerConnection) {
                peerConnection.close();
            }
            
            if (signalPolling) {
                clearInterval(signalPolling);
            }
            
            // Notify server we left
            const formData = new FormData();
            formData.append('participant_id', participantId);
            formData.append('room_token', roomToken);
            
            await fetch('?api=end_call', {
                method: 'POST',
                body: formData
            });
            
            showToast('Call ended', 'warning');
            setTimeout(() => {
                window.location.href = './';
            }, 2000);
        }
        
        function sendQuickMessage(message) {
            sendSignal('control', JSON.stringify({
                type: 'chat',
                message: message,
                from: userName,
                timestamp: new Date().toISOString()
            }));
            showToast(`Sent: ${message}`, 'info');
        }
        
        // ==================== HOST PANEL FUNCTIONS ====================
        async function updateRoomSettings() {
            const maxParticipants = document.getElementById('settingsMaxParticipants').value;
            const requireApproval = document.getElementById('settingsRequireApproval').checked ? 1 : 0;
            
            const formData = new FormData();
            formData.append('room_token', roomToken);
            formData.append('max_participants', maxParticipants);
            formData.append('requires_approval', requireApproval);
            
            try {
                const response = await fetch('?api=update_settings', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast('Settings updated successfully!', 'success');
                    loadRoomInfo();
                }
            } catch (error) {
                showToast('Error updating settings', 'error');
            }
        }
        
        async function loadRoomInfo() {
            const formData = new FormData();
            formData.append('room_token', roomToken);
            
            try {
                const response = await fetch('?api=get_room_info', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    const room = result.room;
                    const participants = result.participants;
                    
                    // Update room info display
                    document.getElementById('roomInfo').innerHTML = `
                        <p><strong>Room:</strong> ${room.room_name}</p>
                        <p><strong>Host:</strong> ${room.host_name}</p>
                        <p><strong>Participants:</strong> ${room.current_participants}/${room.max_participants}</p>
                        <p><strong>Status:</strong> ${room.is_active ? '🟢 Active' : '🔴 Inactive'}</p>
                        <p><strong>Created:</strong> ${new Date(room.created_at).toLocaleString()}</p>
                    `;
                    
                    // Update room link
                    const baseUrl = window.location.origin + window.location.pathname;
                    document.getElementById('roomLinkDisplay').textContent = `${baseUrl}?join=${roomToken}`;
                    
                    // Update settings form
                    document.getElementById('settingsMaxParticipants').value = room.max_participants;
                    document.getElementById('settingsRequireApproval').checked = room.requires_approval;
                    
                    // Update active participants
                    updateParticipantsList(participants);
                }
            } catch (error) {
                console.error('Error loading room info:', error);
            }
        }
        
        async function loadPendingRequests() {
            const formData = new FormData();
            formData.append('room_token', roomToken);
            
            try {
                const response = await fetch('?api=get_pending_requests', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    const container = document.getElementById('approvalRequests');
                    
                    if (result.requests.length === 0) {
                        container.innerHTML = '<p>No pending requests</p>';
                        return;
                    }
                    
                    let html = '';
                    result.requests.forEach(req => {
                        html += `
                            <div class="participant-card waiting">
                                <div>
                                    <strong>${req.user_name}</strong><br>
                                    <small>${req.device_name}</small><br>
                                    <small>Joined: ${new Date(req.joined_at).toLocaleTimeString()}</small>
                                </div>
                                <div>
                                    <button class="btn btn-success" onclick="approveRequest('${req.participant_id}', true)" style="padding: 8px 15px; margin: 2px;">✅</button>
                                    <button class="btn btn-danger" onclick="approveRequest('${req.participant_id}', false)" style="padding: 8px 15px; margin: 2px;">❌</button>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                }
            } catch (error) {
                console.error('Error loading requests:', error);
            }
        }
        
        async function approveRequest(reqId, approve) {
            const formData = new FormData();
            formData.append('participant_id', reqId);
            formData.append('approve', approve ? 1 : 0);
            
            try {
                const response = await fetch('?api=approve_request', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    showToast(`Request ${approve ? 'approved' : 'rejected'}`, 'success');
                    loadPendingRequests();
                    loadRoomInfo();
                }
            } catch (error) {
                showToast('Error processing request', 'error');
            }
        }
        
        function updateParticipantsList(participants) {
            const container = document.getElementById('activeParticipants');
            
            if (participants.length === 0) {
                container.innerHTML = '<p>No active participants yet</p>';
                return;
            }
            
            let html = '';
            participants.forEach(p => {
                const statusClass = p.status === 'active' ? 'badge-active' : 'badge-approved';
                html += `
                    <div class="participant-card approved">
                        <div>
                            <strong>${p.user_name}</strong>
                            <span class="status-badge ${statusClass}">${p.status}</span><br>
                            <small>${p.device_name}</small><br>
                            <small>Joined: ${new Date(p.joined_at).toLocaleTimeString()}</small>
                        </div>
                        <button class="btn btn-danger" onclick="kickParticipant('${p.participant_id}')" style="padding: 8px 15px;">
                            👢 Kick
                        </button>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        async function kickParticipant(participantId) {
            if (confirm('Are you sure you want to kick this participant?')) {
                await approveRequest(participantId, false);
            }
        }
        
        function lockRoom() {
            // Implementation for locking room
            showToast('Room locked (no new participants can join)', 'warning');
        }
        
        function endRoomForAll() {
            if (confirm('End room for all participants? This will disconnect everyone.')) {
                // Send disconnect signal to all
                sendSignal('control', JSON.stringify({
                    type: 'room_end',
                    message: 'Host ended the room',
                    timestamp: new Date().toISOString()
                }));
                
                showToast('Room ended for all participants', 'warning');
                setTimeout(() => {
                    window.location.href = './';
                }, 3000);
            }
        }
        
        // ==================== PAGE INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($page == 'join'): ?>
                // Auto-join room
                if (!participantId) {
                    // First time joining, register
                    setTimeout(async () => {
                        const formData = new FormData();
                        formData.append('room_token', roomToken);
                        formData.append('user_name', '<?php echo $_GET["name"] ?? "Guest"; ?>');
                        
                        try {
                            const response = await fetch('?api=join_room', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            if (result.success) {
                                participantId = result.participant_id;
                                document.getElementById('statusMessage').innerHTML = `
                                    <p>✅ Joined as ${result.device}</p>
                                    ${result.requires_approval ? 
                                        '<p class="badge-waiting status-badge">⏳ Waiting for host approval...</p>' : 
                                        '<p class="badge-approved status-badge">✅ Approved! Starting call...</p>'
                                    }
                                `;
                                
                                if (!result.requires_approval) {
                                    // Auto-approved, start WebRTC
                                    setTimeout(() => {
                                        document.getElementById('joinStatus').style.display = 'none';
                                        document.getElementById('callInterface').style.display = 'block';
                                        initializeWebRTC();
                                    }, 1000);
                                } else {
                                    // Wait for approval
                                    startApprovalPolling();
                                }
                            } else {
                                document.getElementById('statusMessage').innerHTML = 
                                    `<p class="badge-rejected status-badge">❌ ${result.error}</p>`;
                            }
                        } catch (error) {
                            document.getElementById('statusMessage').innerHTML = 
                                `<p class="badge-rejected status-badge">❌ Network error</p>`;
                        }
                    }, 500);
                } else {
                    // Already joined, just start WebRTC
                    document.getElementById('joinStatus').style.display = 'none';
                    document.getElementById('callInterface').style.display = 'block';
                    initializeWebRTC();
                }
                
                function startApprovalPolling() {
                    const checkApproval = setInterval(async () => {
                        const formData = new FormData();
                        formData.append('room_token', roomToken);
                        
                        const response = await fetch('?api=get_room_info', {method: 'POST', body: formData});
                        const result = await response.json();
                        
                        if (result.success) {
                            const me = result.participants.find(p => p.participant_id === participantId);
                            if (me && me.status === 'approved') {
                                clearInterval(checkApproval);
                                document.getElementById('joinStatus').style.display = 'none';
                                document.getElementById('callInterface').style.display = 'block';
                                initializeWebRTC();
                            } else if (me && me.status === 'rejected') {
                                clearInterval(checkApproval);
                                document.getElementById('statusMessage').innerHTML = 
                                    '<p class="badge-rejected status-badge">❌ Host rejected your request</p>';
                                setTimeout(() => window.location.href = './', 3000);
                            }
                        }
                    }, 2000);
                }
                
            <?php elseif ($page == 'host'): ?>
                // Initialize host panel
                loadRoomInfo();
                loadPendingRequests();
                
                // Refresh data every 3 seconds
                pollingInterval = setInterval(() => {
                    loadRoomInfo();
                    loadPendingRequests();
                }, 3000);
                
                // Clean up on page unload
                window.addEventListener('beforeunload', () => {
                    if (pollingInterval) clearInterval(pollingInterval);
                });
                
            <?php endif; ?>
        });
    </script>
</body>
  </html>
