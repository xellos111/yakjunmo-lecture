<?php
/**
 * 라이믹스 후기/방명록 전용 API (CORS 허용)
 *
 * [설치 방법]
 * 이 파일을 라이믹스가 설치된 최상위 경로 (index.php 가 있는 위치)에 `api_reviews.php` 이름으로 업로드하세요.
 */

// 1. CORS 설정 (Apache 리버스 프록시 환경 고려)
// day.xellos.top 에서만 접근 가능하도록 설정
header("Access-Control-Allow-Origin: https://day.xellos.top");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");
header('Content-Type: application/json; charset=utf-8');

// 보안: OPTIONS 메서드(Preflight) 요청 시 바로 200 OK 응답 후 종료
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. 라이믹스 코어 로드
define('__XE__', true);
require_once('./config/config.inc.php');

$oContext = Context::getInstance();
$oContext->init();

// 작성될 게시판의 대상 문서 번호 (특강 후기 글 번호)
$target_document_srl = 640;
// 게시판 모듈 이름
$target_mid = 'board';

$method = $_SERVER['REQUEST_METHOD'];

// ==========================================
// [GET] 댓글 목록 불러오기
// ==========================================
if ($method === 'GET') {
    $oCommentModel = getModel('comment');

    // 문서에 달린 댓글 목록을 가져옴 (오래된 순 기준 등은 라이믹스 설정에 따름)
    $output = $oCommentModel->getCommentList($target_document_srl, 0, false);

    $comments = array();

    if($output->data) {
        foreach($output->data as $comment) {
            // 비밀 댓글은 제외 (is_secret 프로퍼티 확인)
            if(isset($comment->is_secret) && $comment->is_secret === 'Y') continue;

            $comments[] = array(
                'id' => $comment->comment_srl,
                'author' => $comment->nick_name,
                'dateText' => zdate($comment->regdate, "Y.m.d H:i"),
                // HTML 태그는 디코드하되, index.html의 onclick 속성(따옴표)을 깨지 않기 위해 내용안의 따옴표를 이스케이프 처리
                'content' => htmlspecialchars_decode($comment->content) 
            );
        }
    }

    echo json_encode(array(
        'status' => 'success',
        'data' => $comments
    ), JSON_UNESCAPED_UNICODE);
    exit();
}

// ==========================================
// [POST] 새 댓글 등록 / 수정 / 삭제
// ==========================================
if ($method === 'POST') {
    // 1. 전달받은 기본 파라미터 확인 ('action'은 라이믹스 예약어일 수 있으므로 'rx_mode' 사용)
    $rx_mode = Context::get('rx_mode') ?: 'insert';
    $nick_name = Context::get('nick_name');
    $password = Context::get('password');
    $content = Context::get('content');
    $comment_srl = Context::get('comment_srl'); // 수정 시 필요

    // 코어 객체 사용을 위한 설정
    $oCommentController = getController('comment');
    $oCommentModel = getModel('comment');
    $oDocumentModel = getModel('document');

    // 대상 문서 정보 확인
    $oDocument = $oDocumentModel->getDocument($target_document_srl);
    if(!$oDocument->isExists()) {
        http_response_code(404);
        echo json_encode(array('status' => 'error', 'message' => '대상 게시글을 찾을 수 없습니다.'), JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ------------------------------------
    // [POST] 새 댓글 등록 (insert)
    // ------------------------------------
    if ($rx_mode === 'insert') {
        // 필수 값 누락 시
        if (!$nick_name || !$password || !$content) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => '이름, 비밀번호, 내용을 모두 입력해주세요.'), JSON_UNESCAPED_UNICODE);
            exit();
        }

        // 3. 댓글 객체(Object) 생성
        $obj = new stdClass();
        $obj->document_srl = $target_document_srl;
        $obj->module_srl = $oDocument->get('module_srl'); // 게시판 모듈 번호
        $obj->content = $content;
        $obj->nick_name = strip_tags($nick_name);
        $obj->password = $password;

        // 4. 라이믹스 댓글 코어 함수 호출 (수동 입력 모드: CSRF 등 우회)
        $output = $oCommentController->insertComment($obj, true);

        if (!$output->toBool()) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'message' => $output->getMessage() ?: '등록에 실패했습니다.'), JSON_UNESCAPED_UNICODE);
            exit();
        }

        echo json_encode(array('status' => 'success', 'message' => '등록되었습니다.'), JSON_UNESCAPED_UNICODE);
        exit();
    }

    // ------------------------------------
    // [POST] 댓글 수정 (update) 또는 삭제 (delete)
    // ------------------------------------
    if ($rx_mode === 'update' || $rx_mode === 'delete') {
        if (!$comment_srl || !$password) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => '댓글 번호와 비밀번호가 필요합니다.'), JSON_UNESCAPED_UNICODE);
            exit();
        }

        // 대상 댓글 가져오기
        $oComment = $oCommentModel->getComment($comment_srl);
        if (!$oComment->isExists() || $oComment->get('document_srl') != $target_document_srl) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => '존재하지 않거나 권한이 없는 댓글입니다.'), JSON_UNESCAPED_UNICODE);
            exit();
        }

        // 비밀번호 확인 로직 (라이믹스 코어 방식)
        $oMemberModel = getModel('member');
        if (!$oMemberModel->isValidPassword($oComment->get('password'), $password) && $oComment->get('password') !== $password) {
             http_response_code(403);
             echo json_encode(array('status' => 'error', 'message' => '비밀번호가 일치하지 않습니다.'), JSON_UNESCAPED_UNICODE);
             exit();
        }

        // 권한 인증 허가 설정
        $_SESSION['own_comment'][$comment_srl] = true;
        $oComment->setGrant();

        if ($rx_mode === 'delete') {
            // 삭제 처리
            $output = $oCommentController->deleteComment($comment_srl,true);

            if (!$output->toBool()) {
                http_response_code(500);
                echo json_encode(array('status' => 'error', 'message' => $output->getMessage() ?: '삭제에 실패했습니다.'), JSON_UNESCAPED_UNICODE);
                exit();
            }

            echo json_encode(array('status' => 'success', 'message' => '삭제되었습니다.'), JSON_UNESCAPED_UNICODE);
            exit();

        } else if ($rx_mode === 'update') {
            if (!$content) {
                http_response_code(400);
                echo json_encode(array('status' => 'error', 'message' => '수정할 내용을 입력해주세요.'), JSON_UNESCAPED_UNICODE);
                exit();
            }

            // Update object
            $newObj = new stdClass();
            $newObj->comment_srl = $comment_srl;
            $newObj->document_srl = $target_document_srl;
            $newObj->module_srl = $oDocument->get('module_srl');
            
            // Allow name change if provided, otherwise keep existing
            $newObj->nick_name = $nick_name ? strip_tags($nick_name) : $oComment->get('nick_name');
            $newObj->content = $content;
            $newObj->password = $password; // Required for core password check if needed
            $newObj->is_secret = 'N';
            $newObj->status = 1;
            
            // 3rd param = true (manual_updated) skips CSRF check!
            $output = $oCommentController->updateComment($newObj, true, true);

            if (!$output->toBool()) {
                http_response_code(500);
                echo json_encode(array('status' => 'error', 'message' => $output->getMessage() ?: '수정에 실패했습니다.'), JSON_UNESCAPED_UNICODE);
                exit();
            }

            echo json_encode(array('status' => 'success', 'message' => '수정되었습니다.'), JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
}

// 다른 Method 사용 불가
http_response_code(405);
echo json_encode(array('status' => 'error', 'message' => '허용되지 않은 요청입니다.'), JSON_UNESCAPED_UNICODE);
exit();
