<?php
session_start();

// ====================
// DATABASE CONFIG
// ====================
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'voicechat';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Auto-create tables if not exist
createTables();

// ====================
// HELPER FUNCTIONS
// ====================
function createTables() {
    global $conn;
    
    $sql_rooms = "CREATE TABLE IF NOT EXISTS rooms (
        id VARCHAR(32) PRIMARY KEY,
        host_name VARCHAR(100),
        room_name VARCHAR(100) DEFAULT 'My Voice Room',
        max_participants INT DEFAULT 2,
        current_participants INT DEFAULT 0,
        is_active BOOLEAN DEFAULT true,
        requires_approval BOOLEAN DEFAULT true,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $sql_participants = "CREATE TABLE IF NOT EXISTS participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_token VARCHAR(32),
        socket_id VARCHAR(50),
        user_name VARCHAR(100) DEFAULT 'Guest',
        user_agent TEXT,
        ip_address VARCHAR(45),
        device_name VARCHAR(100),
        status ENUM('waiting', 'approved', 'rejected', 'active', 'left') DEFAULT 'waiting',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        approved_at TIMESTAMP NULL,
        INDEX idx_room_token (room_token),
        INDEX idx_status (status)
    )";
    
    $conn->query($sql_rooms);
    $conn->query($sql_participants);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

function getDeviceInfo($userAgent) {
    $device = "Unknown Device";
    
    if (stripos($userAgent, 'iPhone') !== false) $device = "iPhone";
    elseif (stripos($userAgent, 'iPad') !== false) $device = "iPad";
    elseif (stripos($userAgent, 'Android') !== false) $device = "Android";
    elseif (stripos($userAgent, 'Windows') !== false) $device = "Windows PC";
    elseif (stripos($userAgent, 'Macintosh') !== false) $device = "Mac";
    
    if (stripos($userAgent, 'Chrome') !== false) $device .= " (Chrome)";
    elseif (stripos($userAgent, 'Firefox') !== false) $device .= " (Firefox)";
    elseif (stripos($userAgent, 'Safari') !== false) $device .= " (Safari)";
    
    return $device;
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// ====================
// API ENDPOINTS
// ====================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        
        case 'create_room':
            $roomToken = generateToken();
            $hostName = $_POST['host_name'] ?? 'Host';
            $roomName = $_POST['room_name'] ?? 'Voice Chat Room';
            $maxParticipants = $_POST['max_participants'] ?? 2;
            
            $stmt = $conn->prepare("INSERT INTO rooms (id, host_name, room_name, max_participants) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $roomToken, $hostName, $roomName, $maxParticipants);
            
            if ($stmt->execute()) {
                $_SESSION['room_token'] = $roomToken;
                $_SESSION['is_host'] = true;
                echo json_encode([
                    'success' => true,
                    'room_token' => $roomToken,
                    'room_link' => "http://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?room=$roomToken",
                    'host_panel' => "http://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?host=$roomToken"
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create room']);
            }
            exit;
            
        case 'join_room':
            $roomToken = $_POST['room_token'] ?? '';
            $userName = $_POST['user_name'] ?? 'Guest';
            
            // Check if room exists and has capacity
            $room = $conn->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
            $room->bind_param("s", $roomToken);
            $room->execute();
            $roomResult = $room->get_result()->fetch_assoc();
            
            if (!$roomResult) {
                echo json_encode(['success' => false, 'error' => 'Room not found']);
                exit;
            }
            
            // Check capacity
            $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM participants WHERE room_token = ? AND status IN ('approved', 'active')");
            $countStmt->bind_param("s", $roomToken);
            $countStmt->execute();
            $count = $countStmt->get_result()->fetch_assoc()['count'];
            
            if ($count >= $roomResult['max_participants']) {
                echo json_encode(['success' => false, 'error' => 'Room is full']);
                exit;
            }
            
            // Add participant
            $deviceInfo = getDeviceInfo($_SERVER['HTTP_USER_AGENT']);
            $ip = getClientIP();
            
            $stmt = $conn->prepare("INSERT INTO participants (room_token, user_name, user_agent, ip_address, device_name, status) VALUES (?, ?, ?, ?, ?, 'waiting')");
            $stmt->bind_param("sssss", $roomToken, $userName, $_SERVER['HTTP_USER_AGENT'], $ip, $deviceInfo);
            
            if ($stmt->execute()) {
                $participantId = $conn->insert_id;
                $_SESSION['participant_id'] = $participantId;
                $_SESSION['room_token'] = $roomToken;
                $_SESSION['user_name'] = $userName;
                
                echo json_encode([
                    'success' => true,
                    'participant_id' => $participantId,
                    'requires_approval' => $roomResult['requires_approval'],
                    'device_info' => $deviceInfo
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to join']);
            }
            exit;
            
        case 'get_approval_requests':
            $roomToken = $_POST['room_token'] ?? '';
            
            $stmt = $conn->prepare("SELECT * FROM participants WHERE room_token = ? AND status = 'waiting' ORDER BY joined_at DESC");
            $stmt->bind_param("s", $roomToken);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            
            echo json_encode(['success' => true, 'requests' => $requests]);
            exit;
            
        case 'approve_request':
            $requestId = $_POST['request_id'] ?? 0;
            $approve = $_POST['approve'] ?? 1;
            
            $status = $approve ? 'approved' : 'rejected';
            $stmt = $conn->prepare("UPDATE participants SET status = ?, approved_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $status, $requestId);
            
            if ($stmt->execute()) {
                if ($approve) {
                    // Update room participant count
                    $conn->query("UPDATE rooms SET current_participants = current_participants + 1 WHERE id = (SELECT room_token FROM participants WHERE id = $requestId)");
                }
                echo json_encode(['success' => true, 'status' => $status]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Update failed']);
            }
            exit;
            
        case 'get_room_info':
            $roomToken = $_POST['room_token'] ?? '';
            
            $stmt = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->bind_param("s", $roomToken);
            $stmt->execute();
            $room = $stmt->get_result()->fetch_assoc();
            
            $stmt2 = $conn->prepare("SELECT * FROM participants WHERE room_token = ? AND status IN ('approved', 'active')");
            $stmt2->bind_param("s", $roomToken);
            $stmt2->execute();
            $participants = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true,
                'room' => $room,
                'participants' => $participants
            ]);
            exit;
            
        case 'update_settings':
            $roomToken = $_POST['room_token'] ?? '';
            $maxParticipants = $_POST['max_participants'] ?? 2;
            $requiresApproval = $_POST['requires_approval'] ?? 1;
            
            $stmt = $conn->prepare("UPDATE rooms SET max_participants = ?, requires_approval = ? WHERE id = ?");
            $stmt->bind_param("iis", $maxParticipants, $requiresApproval, $roomToken);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Update failed']);
            }
            exit;
    }
}

// ====================
// HTML INTERFACE
// ====================
$page = 'home';
if (isset($_GET['room'])) {
    $page = 'join_room';
} elseif (isset($_GET['host'])) {
    $page = 'host_panel';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voice Chat P2P</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* HOME PAGE */
        .card { background: #16213e; border-radius: 10px; padding: 30px; margin: 20px 0; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .btn { background: #0f4c75; color: white; border: none; padding: 12px 24px; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 10px 5px; transition: background 0.3s; }
        .btn:hover { background: #3282b8; }
        .btn-success { background: #00b894; }
        .btn-danger { background: #d63031; }
        .form-group { margin: 15px 0; }
        input, select { width: 100%; padding: 10px; border-radius: 5px; border: 1px solid #0f4c75; background: #1e1e2e; color: white; margin-top: 5px; }
        
        /* HOST PANEL */
        .panel-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .participant-list { background: #0f3460; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; }
        .participant-item { background: #1a1a2e; padding: 10px; margin: 5px 0; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; }
        
        /* VIDEO/AUDIO CALL */
        #videoContainer { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .video-box { background: #0f3460; border-radius: 10px; padding: 20px; text-align: center; }
        video { width: 100%; max-width: 400px; border-radius: 5px; background: black; }
        
        /* TOAST */
        .toast { position: fixed; bottom: 20px; right: 20px; background: #00b894; color: white; padding: 15px; border-radius: 5px; display: none; z-index: 1000; }
        .toast.error { background: #d63031; }
        .toast.warning { background: #fdcb6e; color: #2d3436; }
        
        /* CONTROLS */
        .controls { display: flex; justify-content: center; gap: 10px; margin: 20px 0; }
        .control-btn { width: 60px; height: 60px; border-radius: 50%; border: none; font-size: 24px; cursor: pointer; }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .panel-grid { grid-template-columns: 1fr; }
            .container { padding: 10px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($page === 'home'): ?>
            <!-- HOME PAGE - CREATE ROOM -->
            <div class="card">
                <h1>🎙️ Voice Chat P2P</h1>
                <p>Create a private voice room with approval system</p>
                
                <div class="form-group">
                    <label>Your Name:</label>
                    <input type="text" id="hostName" placeholder="Enter your name" value="Host">
                </div>
                
                <div class="form-group">
                    <label>Room Name:</label>
                    <input type="text" id="roomName" placeholder="Room name" value="My Voice Room">
                </div>
                
                <div class="form-group">
                    <label>Max Participants:</label>
                    <select id="maxParticipants">
                        <option value="2">2 (1-on-1)</option>
                        <option value="4">4</option>
                        <option value="8">8</option>
                        <option value="16">16</option>
                    </select>
                </div>
                
                <button class="btn btn-success" onclick="createRoom()">🎯 Create Room</button>
                
                <div id="roomInfo" style="display:none; margin-top:20px; padding:15px; background:#0f3460; border-radius:5px;">
                    <h3>Room Created! 🎉</h3>
                    <p>Share this link with friends:</p>
                    <input type="text" id="roomLink" readonly style="width:100%; padding:10px; background:#1a1a2e; color:#00b894; border:none; border-radius:3px;">
                    <button class="btn" onclick="copyLink()" style="margin-top:10px;">📋 Copy Link</button>
                    <p style="margin-top:10px;"><a href="#" id="hostLink" style="color:#74b9ff;">Go to Host Control Panel</a></p>
                </div>
            </div>
            
            <!-- JOIN ROOM SECTION -->
            <div class="card">
                <h2>Join Existing Room</h2>
                <div class="form-group">
                    <input type="text" id="joinRoomToken" placeholder="Enter room token from link">
                    <input type="text" id="guestName" placeholder="Your name" value="Guest">
                </div>
                <button class="btn" onclick="joinRoom()">🚪 Join Room</button>
            </div>
            
        <?php elseif ($page === 'join_room'): ?>
            <!-- JOIN ROOM PAGE -->
            <div class="card">
                <h2>Joining Room: <?php echo htmlspecialchars($_GET['room']); ?></h2>
                <div id="joinStatus">
                    <p>Connecting to room...</p>
                    <div class="loader" style="border: 5px solid #f3f3f3; border-top: 5px solid #3498db; border-radius: 50%; width: 50px; height: 50px; animation: spin 2s linear infinite; margin: 20px auto;"></div>
                </div>
                
                <div id="callInterface" style="display:none;">
                    <h3>Voice Call Active 🎧</h3>
                    <div id="videoContainer">
                        <div class="video-box">
                            <h4>You</h4>
                            <video id="localVideo" autoplay muted></video>
                        </div>
                        <div class="video-box">
                            <h4>Remote</h4>
                            <video id="remoteVideo" autoplay></video>
                        </div>
                    </div>
                    
                    <div class="controls">
                        <button class="control-btn" id="muteBtn" onclick="toggleMute()">🎤</button>
                        <button class="control-btn btn-danger" onclick="endCall()">📞</button>
                        <button class="control-btn" id="speakerBtn" onclick="toggleSpeaker()">🔊</button>
                    </div>
                </div>
            </div>
            
        <?php elseif ($page === 'host_panel'): ?>
            <!-- HOST CONTROL PANEL -->
            <div class="card">
                <h1>🎛️ Host Control Panel</h1>
                <div class="panel-grid">
                    <div>
                        <h3>Room Information</h3>
                        <div id="roomInfoPanel">
                            <p>Loading room info...</p>
                        </div>
                        
                        <h3 style="margin-top:20px;">Approval Requests</h3>
                        <div id="approvalRequests" class="participant-list">
                            <p>No pending requests</p>
                        </div>
                        
                        <h3 style="margin-top:20px;">Active Participants</h3>
                        <div id="activeParticipants" class="participant-list">
                            <p>No active participants</p>
                        </div>
                    </div>
                    
                    <div>
                        <h3>Room Settings</h3>
                        <div class="card" style="padding:15px;">
                            <div class="form-group">
                                <label>Max Participants:</label>
                                <select id="settingsMaxParticipants">
                                    <option value="2">2</option>
                                    <option value="4">4</option>
                                    <option value="8">8</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="settingsRequireApproval" checked> Require Approval
                                </label>
                            </div>
                            
                            <button class="btn" onclick="updateSettings()">💾 Save Settings</button>
                            <button class="btn btn-danger" onclick="endRoom()" style="margin-top:10px;">⏹️ End Room</button>
                        </div>
                        
                        <h3 style="margin-top:20px;">Room Link</h3>
                        <div class="card" style="padding:15px;">
                            <input type="text" id="roomLinkDisplay" readonly style="width:100%; padding:8px; margin-bottom:10px;">
                            <button class="btn" onclick="copyRoomLink()">📋 Copy Link</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- TOAST NOTIFICATION -->
        <div id="toast" class="toast"></div>
    </div>
    
    <!-- SOCKET.IO & WEBRTC SCRIPT -->
    <script src="https://cdn.socket.io/4.5.0/socket.io.min.js"></script>
    <script>
        // ====================
        // GLOBAL VARIABLES
        // ====================
        let socket;
        let peerConnection;
        let localStream;
        let roomToken = '<?php echo $_GET["room"] ?? $_GET["host"] ?? ""; ?>';
        let isHost = <?php echo isset($_GET['host']) ? 'true' : 'false'; ?>;
        let participantId = <?php echo $_SESSION['participant_id'] ?? 0; ?>;
        
        // ====================
        // HELPER FUNCTIONS
        // ====================
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied to clipboard!', 'success');
            });
        }
        
        // ====================
        // HOME PAGE FUNCTIONS
        // ====================
        async function createRoom() {
            const hostName = document.getElementById('hostName').value;
            const roomName = document.getElementById('roomName').value;
            const maxParticipants = document.getElementById('maxParticipants').value;
            
            const formData = new FormData();
            formData.append('host_name', hostName);
            formData.append('room_name', roomName);
            formData.append('max_participants', maxParticipants);
            
            const response = await fetch('?action=create_room', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                document.getElementById('roomLink').value = result.room_link;
                document.getElementById('hostLink').href = result.host_panel;
                document.getElementById('roomInfo').style.display = 'block';
                showToast('Room created successfully!', 'success');
            } else {
                showToast('Error: ' + result.error, 'error');
            }
        }
        
        function copyLink() {
            const link = document.getElementById('roomLink');
            copyToClipboard(link.value);
        }
        
        function copyRoomLink() {
            const link = document.getElementById('roomLinkDisplay');
            copyToClipboard(link.value);
        }
        
        async function joinRoom() {
            const token = document.getElementById('joinRoomToken').value;
            const name = document.getElementById('guestName').value;
            
            if (!token) {
                showToast('Please enter room token', 'warning');
                return;
            }
            
            window.location.href = `?room=${token}&name=${encodeURIComponent(name)}`;
        }
        
        // ====================
        // WEBRTC FUNCTIONS
        // ====================
        async function initializeWebRTC() {
            try {
                // Get microphone access
                localStream = await navigator.mediaDevices.getUserMedia({ 
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    },
                    video: false
                });
                
                const localVideo = document.getElementById('localVideo');
                if (localVideo) {
                    localVideo.srcObject = localStream;
                }
                
                // Create peer connection
                const config = {
                    iceServers: [
                        { urls: 'stun:stun.l.google.com:19302' },
                        { urls: 'stun:stun1.l.google.com:19302' }
                    ]
                };
                
                peerConnection = new RTCPeerConnection(config);
                
                // Add local stream to connection
                localStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localStream);
                });
                
                // Handle incoming audio
                peerConnection.ontrack = (event) => {
                    const remoteVideo = document.getElementById('remoteVideo');
                    if (remoteVideo) {
                        remoteVideo.srcObject = event.streams[0];
                    }
                };
                
                // Handle ICE candidates
                peerConnection.onicecandidate = (event) => {
                    if (event.candidate && socket) {
                        socket.emit('ice-candidate', {
                            candidate: event.candidate,
                            room: roomToken
                        });
                    }
                };
                
                // Connect to signaling server
                initializeSocket();
                
            } catch (error) {
                console.error('WebRTC Error:', error);
                showToast('Error accessing microphone: ' + error.message, 'error');
            }
        }
        
        // ====================
        // SOCKET.IO FUNCTIONS
        // ====================
        function initializeSocket() {
            // Connect to signaling server (you need to run Node.js server separately)
            // For demo, we'll simulate with polling
            socket = io('http://localhost:3000');
            
            socket.on('connect', () => {
                console.log('Connected to signaling server');
                
                if (isHost) {
                    socket.emit('host-join', roomToken);
                    startHostPolling();
                } else {
                    socket.emit('guest-join', {
                        room: roomToken,
                        participantId: participantId,
                        userName: '<?php echo $_SESSION["user_name"] ?? "Guest"; ?>'
                    });
                }
            });
            
            socket.on('offer', async (data) => {
                await peerConnection.setRemoteDescription(new RTCSessionDescription(data.offer));
                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);
                socket.emit('answer', { answer: answer, room: roomToken });
            });
            
            socket.on('answer', async (data) => {
                await peerConnection.setRemoteDescription(new RTCSessionDescription(data.answer));
            });
            
            socket.on('ice-candidate', async (data) => {
                try {
                    await peerConnection.addIceCandidate(new RTCIceCandidate(data.candidate));
                } catch (error) {
                    console.error('Error adding ICE candidate:', error);
                }
            });
            
            socket.on('approved', () => {
                document.getElementById('joinStatus').style.display = 'none';
                document.getElementById('callInterface').style.display = 'block';
                showToast('Request approved! Starting call...', 'success');
                
                // Start WebRTC offer
                if (!isHost) {
                    createOffer();
                }
            });
            
            socket.on('rejected', (data) => {
                showToast('Host rejected your request: ' + (data.message || ''), 'error');
                setTimeout(() => {
                    window.location.href = './';
                }, 3000);
            });
            
            socket.on('room-full', () => {
                showToast('Room is full. Cannot join.', 'warning');
                setTimeout(() => {
                    window.location.href = './';
                }, 3000);
            });
        }
        
        async function createOffer() {
            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            socket.emit('offer', { offer: offer, room: roomToken });
        }
        
        // ====================
        // CALL CONTROLS
        // ====================
        function toggleMute() {
            if (localStream) {
                const audioTrack = localStream.getAudioTracks()[0];
                audioTrack.enabled = !audioTrack.enabled;
                const btn = document.getElementById('muteBtn');
                btn.textContent = audioTrack.enabled ? '🎤' : '🔇';
                btn.style.background = audioTrack.enabled ? '#0f4c75' : '#d63031';
            }
        }
        
        function toggleSpeaker() {
            const remoteVideo = document.getElementById('remoteVideo');
            if (remoteVideo) {
                remoteVideo.muted = !remoteVideo.muted;
                const btn = document.getElementById('speakerBtn');
                btn.textContent = remoteVideo.muted ? '🔇' : '🔊';
            }
        }
        
        function endCall() {
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
            }
            if (peerConnection) {
                peerConnection.close();
            }
            if (socket) {
                socket.disconnect();
            }
            showToast('Call ended', 'warning');
            setTimeout(() => {
                window.location.href = './';
            }, 2000);
        }
        
        // ====================
        // HOST PANEL FUNCTIONS
        // ====================
        async function startHostPolling() {
            // Poll for approval requests
            setInterval(async () => {
                const formData = new FormData();
                formData.append('room_token', roomToken);
                
                const response = await fetch('?action=get_approval_requests', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success) {
                    updateApprovalRequests(result.requests);
                }
                
                // Update room info
                updateRoomInfo();
            }, 2000);
        }
        
        function updateApprovalRequests(requests) {
            const container = document.getElementById('approvalRequests');
            if (requests.length === 0) {
                container.innerHTML = '<p>No pending requests</p>';
                return;
            }
            
            let html = '';
            requests.forEach(req => {
                html += `
                    <div class="participant-item">
                        <div>
                            <strong>${req.user_name}</strong><br>
                            <small>${req.device_name}</small><br>
                            <small>Joined: ${new Date(req.joined_at).toLocaleTimeString()}</small>
                        </div>
                        <div>
                            <button class="btn btn-success" onclick="approveRequest(${req.id}, true)">✅</button>
                            <button class="btn btn-danger" onclick="approveRequest(${req.id}, false)">❌</button>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        async function approveRequest(requestId, approve) {
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('approve', approve ? 1 : 0);
            
            const response = await fetch('?action=approve_request', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                showToast(`Request ${approve ? 'approved' : 'rejected'}`, 'success');
                
                // Notify guest via socket
                if (socket) {
                    socket.emit('host-decision', {
                        requestId: requestId,
                        approved: approve,
                        room: roomToken
                    });
                }
            }
        }
        
        async function updateRoomInfo() {
            const formData = new FormData();
            formData.append('room_token', roomToken);
            
            const response = await fetch('?action=get_room_info', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                const room = result.room;
                const participants = result.participants;
                
                // Update room info
                document.getElementById('roomInfoPanel').innerHTML = `
                    <p><strong>Room:</strong> ${room.room_name}</p>
                    <p><strong>Host:</strong> ${room.host_name}</p>
                    <p><strong>Participants:</strong> ${room.current_participants}/${room.max_participants}</p>
                    <p><strong>Created:</strong> ${new Date(room.created_at).toLocaleString()}</p>
                `;
                
                // Update room link
                document.getElementById('roomLinkDisplay').value = 
                    window.location.origin + window.location.pathname + `?room=${roomToken}`;
                
                // Update settings form
                document.getElementById('settingsMaxParticipants').value = room.max_participants;
                document.getElementById('settingsRequireApproval').checked = room.requires_approval;
                
                // Update active participants
                updateActiveParticipants(participants);
            }
        }
        
        function updateActiveParticipants(participants) {
            const container = document.getElementById('activeParticipants');
            if (participants.length === 0) {
                container.innerHTML = '<p>No active participants</p>';
                return;
            }
            
            let html = '';
            participants.forEach(p => {
                html += `
                    <div class="participant-item">
                        <div>
                            <strong>${p.user_name}</strong><br>
                            <small>${p.device_name}</small><br>
                            <small>Joined: ${new Date(p.joined_at).toLocaleTimeString()}</small>
                        </div>
                        <button class="btn btn-danger" onclick="kickParticipant(${p.id})">👢</button>
                    </div>
                `;
            });
            container.innerHTML = html;
        }
        
        async function kickParticipant(participantId) {
            if (confirm('Kick this participant?')) {
                const formData = new FormData();
                formData.append('request_id', participantId);
                formData.append('approve', 0);
                
                await fetch('?action=approve_request', {
                    method: 'POST',
                    body: formData
                });
                
                showToast('Participant kicked', 'warning');
            }
        }
        
        async function updateSettings() {
            const maxParticipants = document.getElementById('settingsMaxParticipants').value;
            const requiresApproval = document.getElementById('settingsRequireApproval').checked ? 1 : 0;
            
            const formData = new FormData();
            formData.append('room_token', roomToken);
            formData.append('max_participants', maxParticipants);
            formData.append('requires_approval', requiresApproval);
            
            const response = await fetch('?action=update_settings', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.success) {
                showToast('Settings updated!', 'success');
            }
        }
        
        function endRoom() {
            if (confirm('End room and disconnect all participants?')) {
                // Mark room as inactive
                fetch('?action=update_settings&room_token=' + roomToken + '&is_active=0');
                showToast('Room ended', 'warning');
                setTimeout(() => {
                    window.location.href = './';
                }, 2000);
            }
        }
        
        // ====================
        // PAGE INITIALIZATION
        // ====================
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($page === 'join_room'): ?>
                // Auto-join room when page loads
                setTimeout(() => {
                    initializeWebRTC();
                }, 1000);
            <?php elseif ($page === 'host_panel'): ?>
                // Initialize host panel
                updateRoomInfo();
                startHostPolling();
            <?php endif; ?>
            
            // Style for spinner
            const style = document.createElement('style');
            style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        });
    </script>
</body>
</html>
