<?php
/**
 * 강의 후기 댓글 API (외부 React 페이지용)
 *
 * @version 2.2.0
 * @description 모든 요청은 POST, cmd 파라미터로 CRUD 구분
 *              list는 Context::init() 없이 직접 DB 쿼리 (302 리다이렉트 우회)
 *              write 작업은 Context::init() + Rhymix API 사용
 *
 * === 댓글 목록 ===
 * curl -X POST https://www.pharmmaker.com/api/review_comment.php \
 *   -H "Content-Type: application/json" \
 *   -d '{"cmd":"list","document_srl":60732368}'
 *
 * === 댓글 작성 ===
 * curl -X POST https://www.pharmmaker.com/api/review_comment.php \
 *   -H "X-API-TOKEN: 토큰값" \
 *   -H "Content-Type: application/json" \
 *   -d '{"cmd":"create","document_srl":60732368,"nick_name":"김약사","phone_last4":"1234","content":"좋은 강의였습니다"}'
 *
 * === 댓글 수정 ===
 * curl -X POST https://www.pharmmaker.com/api/review_comment.php \
 *   -H "X-API-TOKEN: 토큰값" \
 *   -H "Content-Type: application/json" \
 *   -d '{"cmd":"update","comment_srl":12345,"password":"1234","content":"수정된 후기"}'
 *
 * === 댓글 삭제 ===
 * curl -X POST https://www.pharmmaker.com/api/review_comment.php \
 *   -H "X-API-TOKEN: 토큰값" \
 *   -H "Content-Type: application/json" \
 *   -d '{"cmd":"delete","comment_srl":12345,"password":"1234"}'
 *
 * === 관리자: 전화번호 포함 목록 ===
 * curl -X POST https://www.pharmmaker.com/api/review_comment.php \
 *   -H "X-API-TOKEN: 토큰값" \
 *   -H "Content-Type: application/json" \
 *   -d '{"cmd":"list","document_srl":60732368,"include_phone":"Y"}'
 */

// ============================================
// 설정
// ============================================
define('API_TOKEN', '6668ac8763233486aad91196a8802488354c120ed7672c7bdcb89035a1ab3595');

// ============================================
// CORS 설정
// ============================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-TOKEN');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['message' => 'Only POST method allowed', 'code' => 'METHOD_NOT_ALLOWED']], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 헬퍼 함수
// ============================================
function sendResponse($success, $data = null, $error = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ['success' => $success];
    if ($success && $data !== null) {
        $response['data'] = $data;
    }
    if (!$success && $error) {
        $response['error'] = $error;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function getRequestToken() {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'x-api-token') {
                return $value;
            }
        }
    }
    return $_SERVER['HTTP_X_API_TOKEN'] ?? '';
}

function authenticateOrDie() {
    $token = getRequestToken();
    if ($token !== API_TOKEN) {
        sendResponse(false, null, [
            'message' => 'Unauthorized - Invalid or missing API token',
            'code' => 'INVALID_TOKEN'
        ], 401);
    }
}

// ============================================
// 요청 파싱 (Context::init() 전에 수행)
// ============================================
$_PARSED_INPUT = null;
$_raw = file_get_contents('php://input');
if ($_raw) {
    $decoded = json_decode($_raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        $_PARSED_INPUT = $decoded;
    }
}

function getRequestParams() {
    global $_PARSED_INPUT;
    if ($_PARSED_INPUT) {
        return $_PARSED_INPUT;
    }
    if (!empty($_POST)) {
        return $_POST;
    }
    return [];
}

// ============================================
// Rhymix 환경 로드
// ============================================
define('__XE__', true);
if (!defined('_XE_PATH_')) {
    define('_XE_PATH_', dirname(__DIR__) . '/');
}

// autoload.php: 클래스 로더 + Config::init() + DB 설정 로드
// Context::init()은 아직 호출하지 않음 (list에서 302 유발하므로)
require_once _XE_PATH_ . 'config/config.inc.php';

$params = getRequestParams();
$cmd = trim($params['cmd'] ?? '');

// write 작업만 Context::init() 호출 (list는 직접 DB 쿼리)
if ($cmd !== 'list') {
    $oContext = Context::getInstance();
    $oContext->init();
}

// ============================================
// 라우팅
// ============================================
switch ($cmd) {
    case 'list':
        handleList($params);
        break;
    case 'create':
        authenticateOrDie();
        handleCreate($params);
        break;
    case 'update':
        authenticateOrDie();
        handleUpdate($params);
        break;
    case 'delete':
        authenticateOrDie();
        handleDelete($params);
        break;
    default:
        sendResponse(false, null, [
            'message' => 'Invalid cmd. Use: list, create, update, delete',
            'code' => 'INVALID_CMD'
        ], 400);
}

// ============================================
// 댓글 목록 (Context::init() 없이 직접 DB 쿼리)
// ============================================
function handleList($params) {
    $document_srl = intval($params['document_srl'] ?? 0);
    $page = max(1, intval($params['page'] ?? 1));
    $list_count = min(100, max(1, intval($params['list_count'] ?? 50)));
    $include_phone = ($params['include_phone'] ?? '') === 'Y';

    if (!$document_srl) {
        sendResponse(false, null, [
            'message' => 'document_srl is required',
            'code' => 'MISSING_PARAM'
        ], 400);
    }

    if ($include_phone) {
        $token = getRequestToken();
        if ($token !== API_TOKEN) {
            $include_phone = false;
        }
    }

    $oDB = Rhymix\Framework\DB::getInstance();

    // 게시물 존재 + 댓글 수 확인
    // addPrefixes()가 자동으로 테이블 프리픽스(xe_) 추가
    $doc_stmt = $oDB->query(
        "SELECT document_srl, comment_count FROM documents WHERE document_srl = ? LIMIT 1",
        [$document_srl]
    );
    $doc_rows = $doc_stmt ? $doc_stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    $doc = $doc_rows[0] ?? null;

    if (!$doc) {
        sendResponse(false, null, [
            'message' => 'Document not found',
            'code' => 'DOCUMENT_NOT_FOUND'
        ], 404);
    }

    $total_count = (int)$doc->comment_count;
    $offset = ($page - 1) * $list_count;

    // 댓글 조회 (comments + comments_list 조인, 정렬)
    $columns = "c.comment_srl, c.nick_name, c.content, c.regdate, c.last_update";
    if ($include_phone) {
        $columns .= ", c.homepage";
    }

    $stmt = $oDB->query(
        "SELECT {$columns}
         FROM comments AS c
         INNER JOIN comments_list AS cl ON c.comment_srl = cl.comment_srl
         WHERE cl.document_srl = ? AND c.status >= 1
         ORDER BY cl.head ASC, cl.arrange ASC
         LIMIT ? OFFSET ?",
        [$document_srl, $list_count, $offset]
    );

    $comments = [];
    if ($stmt) {
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
            $item = [
                'comment_srl' => (int)$row->comment_srl,
                'nick_name' => $row->nick_name,
                'content' => strip_tags($row->content),
                'regdate' => $row->regdate,
                'last_update' => $row->last_update,
                'is_edited' => ($row->regdate !== $row->last_update),
            ];
            if ($include_phone) {
                // Rhymix가 homepage에 http:// 자동 추가하므로 제거
                $hp = $row->homepage ?: '';
                $item['phone_last4'] = preg_replace('#^https?://#', '', $hp);
            }
            $comments[] = $item;
        }
    }

    sendResponse(true, [
        'document_srl' => $document_srl,
        'total_count' => $total_count,
        'page' => $page,
        'comments' => $comments,
    ]);
}

// ============================================
// 댓글 작성 (Rhymix API 사용)
// ============================================
function handleCreate($params) {
    $document_srl = intval($params['document_srl'] ?? 0);
    $nick_name = trim($params['nick_name'] ?? '');
    $phone_last4 = trim($params['phone_last4'] ?? '');
    $content = trim($params['content'] ?? '');

    if (!$document_srl || empty($nick_name) || empty($phone_last4) || empty($content)) {
        sendResponse(false, null, [
            'message' => 'Required: document_srl, nick_name, phone_last4, content',
            'code' => 'MISSING_PARAM'
        ], 400);
    }

    if (!preg_match('/^\d{4}$/', $phone_last4)) {
        sendResponse(false, null, [
            'message' => 'phone_last4 must be exactly 4 digits',
            'code' => 'INVALID_PHONE'
        ], 400);
    }

    $oDocument = DocumentModel::getDocument($document_srl);
    if (!$oDocument->isExists()) {
        sendResponse(false, null, [
            'message' => 'Document not found',
            'code' => 'DOCUMENT_NOT_FOUND'
        ], 404);
    }

    $module_srl = $oDocument->get('module_srl');

    $obj = new stdClass();
    $obj->document_srl = $document_srl;
    $obj->module_srl = $module_srl;
    $obj->nick_name = $nick_name;
    $obj->content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    $obj->password = $phone_last4;
    $obj->homepage = $phone_last4;
    $obj->parent_srl = 0;
    $obj->is_secret = 'N';
    $obj->notify_message = 'N';
    $obj->status = 1;

    $oCommentController = getController('comment');
    $output = $oCommentController->insertComment($obj, true);

    if ($output->toBool()) {
        $comment_srl = $output->get('comment_srl');
        sendResponse(true, [
            'comment_srl' => (int)$comment_srl,
            'document_srl' => $document_srl,
            'nick_name' => $nick_name,
            'content' => $content,
            'created_at' => date('c'),
        ], 201);
    } else {
        sendResponse(false, null, [
            'message' => $output->getMessage() ?: '댓글 작성 실패',
            'code' => 'INSERT_FAILED'
        ], 500);
    }
}

// ============================================
// 댓글 수정 (Rhymix API 사용)
// ============================================
function handleUpdate($params) {
    $comment_srl = intval($params['comment_srl'] ?? 0);
    $password = trim($params['password'] ?? '');
    $content = trim($params['content'] ?? '');

    if (!$comment_srl || empty($password) || empty($content)) {
        sendResponse(false, null, [
            'message' => 'Required: comment_srl, password, content',
            'code' => 'MISSING_PARAM'
        ], 400);
    }

    $oComment = CommentModel::getComment($comment_srl);
    if (!$oComment->isExists()) {
        sendResponse(false, null, [
            'message' => 'Comment not found',
            'code' => 'COMMENT_NOT_FOUND'
        ], 404);
    }

    $stored_password = $oComment->get('password');
    if (!$stored_password || !\Rhymix\Framework\Password::checkPassword($password, $stored_password)) {
        sendResponse(false, null, [
            'message' => 'Incorrect password',
            'code' => 'WRONG_PASSWORD'
        ], 403);
    }

    $obj = new stdClass();
    $obj->comment_srl = $comment_srl;
    $obj->document_srl = $oComment->get('document_srl');
    $obj->module_srl = $oComment->get('module_srl');
    $obj->nick_name = $oComment->get('nick_name');
    $obj->content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    $obj->password = $password;
    $obj->is_secret = 'N';
    $obj->status = 1;

    $oCommentController = getController('comment');
    // updateComment($obj, $skip_grant_check, $manual_updated)
    // 3rd param=true → CSRF 체크 스킵
    $output = $oCommentController->updateComment($obj, true, true);

    if ($output->toBool()) {
        sendResponse(true, [
            'comment_srl' => $comment_srl,
            'content' => $content,
            'updated_at' => date('c'),
        ]);
    } else {
        sendResponse(false, null, [
            'message' => $output->getMessage() ?: '댓글 수정 실패',
            'code' => 'UPDATE_FAILED'
        ], 500);
    }
}

// ============================================
// 댓글 삭제 (Rhymix API 사용)
// ============================================
function handleDelete($params) {
    $comment_srl = intval($params['comment_srl'] ?? 0);
    $password = trim($params['password'] ?? '');

    if (!$comment_srl || empty($password)) {
        sendResponse(false, null, [
            'message' => 'Required: comment_srl, password',
            'code' => 'MISSING_PARAM'
        ], 400);
    }

    $oComment = CommentModel::getComment($comment_srl);
    if (!$oComment->isExists()) {
        sendResponse(false, null, [
            'message' => 'Comment not found',
            'code' => 'COMMENT_NOT_FOUND'
        ], 404);
    }

    $stored_password = $oComment->get('password');
    if (!$stored_password || !\Rhymix\Framework\Password::checkPassword($password, $stored_password)) {
        sendResponse(false, null, [
            'message' => 'Incorrect password',
            'code' => 'WRONG_PASSWORD'
        ], 403);
    }

    $oCommentController = getController('comment');
    $output = $oCommentController->deleteComment($comment_srl, true);

    if ($output->toBool()) {
        sendResponse(true, [
            'comment_srl' => $comment_srl,
            'deleted_at' => date('c'),
        ]);
    } else {
        sendResponse(false, null, [
            'message' => $output->getMessage() ?: '댓글 삭제 실패',
            'code' => 'DELETE_FAILED'
        ], 500);
    }
}
