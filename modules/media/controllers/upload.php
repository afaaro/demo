<?php

class MediaUpload extends Controller {
    /** Extensions accepted for upload. SVG is excluded (scriptable). */
    private const ALLOWED_EXTENSIONS = [
        'mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'm4v',
        'mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
    ];

    public function indexAction() {
        $channelId = $this->request->get('channel_id', 'int');
        $currentUserId = $this->auth->user('id');

        // Security Check: Verify user has access before rendering form elements
        $allowed = $this->db->query(
            "SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1",
            [$channelId, $currentUserId]
        )->row;

        if (empty($allowed)) {
            $this->notify->error('You do not have permission to manage this creator channel space.');
            return redirect('media/studio');
        }

        // Inject the asynchronous streaming loop javascript cleanly into layout blocks
        $this->asset->addInlineScript("
            function extractVideoThumbnailFrame(file) {
                return new Promise((resolve) => {
                    // Only attempt extraction if the file uploaded is actually a video element type
                    if (!file.type.startsWith('video/')) {
                        resolve(null);
                        return;
                    }

                    const videoNode = document.createElement('video');
                    videoNode.preload = 'metadata';
                    videoNode.muted = true;
                    videoNode.playsInline = true;
                    videoNode.src = URL.createObjectURL(file);

                    videoNode.onloadedmetadata = () => {
                        videoNode.currentTime = Math.min(3, videoNode.duration / 2);
                    };

                    videoNode.onseeked = () => {
                        const canvas = document.createElement('canvas');
                        canvas.width = videoNode.videoWidth;
                        canvas.height = videoNode.videoHeight;
                        
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(videoNode, 0, 0, canvas.width, canvas.height);
                        
                        canvas.toBlob((blob) => {
                            URL.revokeObjectURL(videoNode.src);
                            resolve(blob);
                        }, 'image/jpeg', 0.85);
                    };

                    videoNode.onerror = () => {
                        resolve(null);
                    };
                });
            }

            async function uploadLargeVideoFile(file, channelId) {
                if (!file) return;

                const progressBar = document.getElementById('upload-progress-bar');
                if (progressBar) {
                    progressBar.style.width = '0%';
                    progressBar.classList.remove('d-none');
                    progressBar.innerText = 'Analyzing asset profiles...';
                }

                const thumbnailBlob = await extractVideoThumbnailFrame(file);

                const CSRF_TOKEN = '".$this->session->get('_csrf_token')."';
                const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB structural chunk slices
                const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                const fileUuid = crypto.randomUUID();

                for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                    const startByte = chunkIndex * CHUNK_SIZE;
                    const endByte = Math.min(file.size, startByte + CHUNK_SIZE);
                    const fileSlice = file.slice(startByte, endByte);

                    const formData = new FormData();
                    formData.append('file', fileSlice);

                    const targetEndpoint = `".url('media/upload/chunk')."?channel_id=\${channelId}&uuid=\${fileUuid}&index=\${chunkIndex}&total=\${totalChunks}&filename=\${encodeURIComponent(file.name)}`;

                    try {
                        const response = await fetch(targetEndpoint, { method: 'POST', body: formData, headers: { 'X-CSRF-Token': CSRF_TOKEN } });
                        const result = await response.json();

                        if (result.error) {
                            alert(`Upload Halted: \${result.error}`);
                            return;
                        }

                        const completionPercentage = Math.round(((chunkIndex + 1) / totalChunks) * 100);
                        if (progressBar) {
                            progressBar.style.width = `\${completionPercentage}%`;
                            progressBar.innerText = `Uploading... \${completionPercentage}%`;
                        }

                        if (result.completed) {
                            if (progressBar) progressBar.innerText = 'Processing complete! Saving file details...';
                            saveVideoMetadataToDatabase(result.filepath, file.name, channelId, thumbnailBlob);
                        }

                    } catch (error) {
                        alert('Network communication fault detected. Please check your connection.');
                        return;
                    }
                }
            }

            async function saveVideoMetadataToDatabase(savedFilePath, naturalName, channelId, thumbnailBlob) {
                const targetEndpoint = `".url('media/upload/save')."`;
                const formData = new FormData();
                
                formData.append('channel_id', channelId);
                formData.append('file_path', savedFilePath);
                formData.append('title', naturalName.replace(/\.[^/.]+$/, ''));
                
                if (thumbnailBlob) {
                    formData.append('thumbnail', thumbnailBlob, 'preview.jpg');
                }
                
                try {
                    const response = await fetch(targetEndpoint, {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]') ? document.querySelector('meta[name=csrf-token]').content : '".$this->session->get('_csrf_token')."' }
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        window.location.href = `".url('media/studio')."?channel_id=\${channelId}`;
                    } else {
                        alert('Error saving video records: ' + result.error);
                    }
                } catch(e) {
                    alert('Failed to register file properties with the engine container.');
                }
            }
        ");

        ?>
        <div class="container py-5">
            <div class="card shadow border-0 max-width-600 mx-auto">
                <div class="card-body p-5">
                    <h5 class="font-weight-bold mb-4"><i class="fas fa-cloud-upload-alt me-2 text-primary"></i>Upload Studio Video Ingest</h5>
                    
                    <div class="mb-4">
                        <label class="form-label font-weight-bold">Select Media File</label>
                        <input type="file" id="video-file-picker" class="form-control" accept="video/*,audio/*,image/*" onchange="uploadLargeVideoFile(this.files[0], <?= $channelId; ?>)">
                    </div>

                    <div class="progress mb-3" style="height: 25px;">
                        <div id="upload-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-success d-none" role="progressbar" style="width: 0%;">0%</div>
                    </div>
                </div>
            </div>
        </div>
        <?php

        $this->load->view();
    }

    /**
     * Endpoint to Handle Chunked File Upload Fragments Securely
     * Route: /media/upload/chunk
     */
    public function chunkAction() {
        header('Content-Type: application/json');

        if (!$this->request->isPost() || !$this->request->verifyToken()) {
            echo json_encode(['error' => 'Invalid request.']);
            return;
        }

        $channelId     = $this->request->get('channel_id', 'int');
        $currentUserId = $this->auth->user('id');

        // 1. Authorize channel permissions
        $allowed = $this->db->query(
            "SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1",
            [$channelId, $currentUserId]
        )->row;

        if (empty($allowed)) {
            echo json_encode(['error' => 'Unauthorized channel access privilege.']);
            return;
        }

        // 2. Extract Chunk Parameters
        $fileUuid   = preg_replace('/[^a-zA-Z0-9\_-]/', '', $this->request->get('uuid', 'string'));
        $chunkIndex = $this->request->get('index', 'int');
        $totalChunks = $this->request->get('total', 'int');
        $fileName   = preg_replace('/[^a-zA-Z0-9\._-]/', '', $this->request->get('filename', 'string'));

        $maxChunks = 400; // 400 x 5MB = ~2GB ceiling
        if ($totalChunks < 1 || $totalChunks > $maxChunks || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            echo json_encode(['error' => 'Invalid chunk parameters.']);
            return;
        }

        $uploadExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($uploadExt, self::ALLOWED_EXTENSIONS, true)) {
            echo json_encode(['error' => 'This file type is not allowed.']);
            return;
        }

        if (empty($fileUuid) || empty($fileName) || isset($_FILES['file']) === false) {
            echo json_encode(['error' => 'Missing vital structural chunk parameters.']);
            return;
        }

        // 3. Staging Directories Construction
        $absoluteRoot  = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR; 
        $tempDirectory = $absoluteRoot . 'uploads' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $fileUuid . DIRECTORY_SEPARATOR;
        $tempDirectory = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $tempDirectory);

        if (!is_dir($tempDirectory)) {
            mkdir($tempDirectory, 0755, true);
        }

        $targetChunkFile = $tempDirectory . $chunkIndex . '.part';

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetChunkFile)) {
            echo json_encode(['error' => 'Failed to save binary data fragment to storage node.']);
            return;
        }

        // 4. Verify Assembly Readiness
        $assembledParts = glob($tempDirectory . '*.part');
        
        if (count($assembledParts) === $totalChunks) {
            $finalFileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // --- DYNAMIC TYPE ROUTING LOGIC ENGINE ---
            $mediaSubfolder = 'videos'; // Default fallback path mapping context
            
            $videoExtensions = ['mp4', 'webm', 'mkv', 'avi', 'mov', 'flv', 'wmv', 'm4v'];
            $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma'];
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];

            if (in_array($finalFileExtension, $audioExtensions)) {
                $mediaSubfolder = 'audio';
            } elseif (in_array($finalFileExtension, $imageExtensions)) {
                $mediaSubfolder = 'images';
            } elseif (in_array($finalFileExtension, $videoExtensions)) {
                $mediaSubfolder = 'videos';
            }
            // ------------------------------------------

            $finalUploadDir = $absoluteRoot . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $mediaSubfolder . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR . date('d') . DIRECTORY_SEPARATOR;
            $finalUploadDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $finalUploadDir);

            if (!is_dir($finalUploadDir)) {
                mkdir($finalUploadDir, 0755, true);
            }

            $finalSecuredName   = bin2hex(random_bytes(16)) . '.' . $finalFileExtension;
            $finalPathTarget    = $finalUploadDir . $finalSecuredName;

            $outputStream = fopen($finalPathTarget, 'wb');
            if (!$outputStream) {
                echo json_encode(['error' => 'Failed to open final file stream matrix.']);
                return;
            }

            for ($i = 0; $i < $totalChunks; $i++) {
                $partPath = $tempDirectory . $i . '.part';
                $inputStream = fopen($partPath, 'rb');
                
                if ($inputStream) {
                    while ($buffer = fread($inputStream, 4096)) {
                        fwrite($outputStream, $buffer);
                    }
                    fclose($inputStream);
                    unlink($partPath); 
                } else {
                    fclose($outputStream);
                    echo json_encode(['error' => "Data corruption alert: Lost segment tracking index {$i}."]);
                    return;
                }
            }

            fclose($outputStream);
            rmdir($tempDirectory); 

            // Construct standardized relative paths for database entries
            $relativeWebPath = 'uploads/media/' . $mediaSubfolder . '/' . date('Y/m/d') . '/' . $finalSecuredName;

            echo json_encode([
                'success'   => true,
                'completed' => true,
                'filepath'  => $relativeWebPath
            ]);
            return;
        }

        echo json_encode([
            'success'   => true,
            'completed' => false,
            'message'   => "Chunk segment {$chunkIndex} logged successfully."
        ]);
    }

    /**
     * Endpoint to Register Consolidated Video Metadata & Extracted Thumbnail Cover
     * Route: /media/upload/save
     */
    public function saveAction() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Invalid request dispatch method context.']);
            return;
        }

        if (!$this->request->verifyToken()) {
            echo json_encode(['error' => 'Invalid or expired security token.']);
            return;
        }

        $channelId     = (int)($_POST['channel_id'] ?? 0);
        $currentUserId = $this->auth->user('id');
        $title         = trim($_POST['title'] ?? '');
        $filePath      = trim($_POST['file_path'] ?? '');
        $thumbnailPath = null;

        // Guardrail: Authorize channel permissions
        $allowed = $this->db->query(
            "SELECT `role` FROM `#__media_channel_staff` WHERE `channel_id` = ? AND `user_id` = ? LIMIT 1",
            [$channelId, $currentUserId]
        )->row;

        if (empty($allowed) || empty($title) || empty($filePath)) {
            echo json_encode(['error' => 'Security policy rejection or missing entity values.']);
            return;
        }

        // The path must be one this upload flow produced: fixed layout, random name, file present.
        $extPattern = implode('|', self::ALLOWED_EXTENSIONS);
        if (!preg_match('#^uploads/media/(videos|audio|images)/\d{4}/\d{2}/\d{2}/[a-f0-9]{32}\.(' . $extPattern . ')$#', $filePath)
            || !is_file(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath))
        ) {
            echo json_encode(['error' => 'Invalid file reference.']);
            return;
        }

        $alreadyRegistered = $this->db->query("SELECT 1 FROM `#__media_videos` WHERE `file_path` = ? LIMIT 1", [$filePath])->row;
        if ($alreadyRegistered) {
            echo json_encode(['error' => 'This file is already registered.']);
            return;
        }

        // Process and save thumbnail image ONLY if one was generated (i.e. for videos)
        if (!empty($_FILES['thumbnail']['tmp_name'])
            && is_uploaded_file($_FILES['thumbnail']['tmp_name'])
            && (@getimagesize($_FILES['thumbnail']['tmp_name'])['mime'] ?? '') === 'image/jpeg'
        ) {
            $absoluteRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
            $thumbDir = $absoluteRoot . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m') . DIRECTORY_SEPARATOR;
            $thumbDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $thumbDir);

            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            // --- REFACTORED FILENAME PARSING IDENTITY LOOP ---
            // Grabs the unique md5 code out of the target string 'uploads/media/videos/YYYY/MM/DD/hash.mp4'
            $videoBaseName = pathinfo($filePath, PATHINFO_FILENAME);
            
            $thumbName = $videoBaseName . '.jpg';
            $targetThumbPath = $thumbDir . $thumbName;
            // -------------------------------------------------

            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $targetThumbPath)) {
                $thumbnailPath = 'uploads/media/thumbnails/' . date('Y/m/') . $thumbName;
            }
        }

        // Write details directly to your media database schema ledger
        $this->db->query("
            INSERT INTO `#__media_videos` (`channel_id`, `uploaded_by`, `title`, `file_path`, `thumbnail_path`, `status`, `created_at`) 
            VALUES (?, ?, ?, ?, ?, 'public', NOW())
        ", [$channelId, $currentUserId, $title, $filePath, $thumbnailPath]);

        echo json_encode(['success' => true]);
    }
}