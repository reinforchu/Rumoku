<?php
// =============================
// Basic auth
// =============================
$dbAuth = new SQLite3('chat.db');
$dbAuth->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
$storedHash = $dbAuth->querySingle("SELECT value FROM settings WHERE key='basic_auth_password'");
if (!$storedHash) {
    $storedHash = password_hash('admin', PASSWORD_DEFAULT);
    $dbAuth->exec("INSERT OR REPLACE INTO settings(key,value) VALUES('basic_auth_password','".$dbAuth->escapeString($storedHash)."')");
}

if (!isset($_GET['share']))
if (
    !isset($_SERVER['PHP_AUTH_USER']) ||
    $_SERVER['PHP_AUTH_USER'] !== basename(getcwd()) ||
    !password_verify($_SERVER['PHP_AUTH_PW'] ?? '', $storedHash)
) {
    header('WWW-Authenticate: Basic realm="Restricted Page"');
    header('HTTP/1.0 401 Unauthorized');
    exit('Authentication required');
}

// =============================
// 設定
// =============================
$API_KEY = "sk------"; // ←自分のキーに置き換えてください
$MODEL   = "gpt-5-nano";       // モデルは適宜変更可

// =============================
// APIキーの妥当性チェック
// =============================
$url = "https://api.openai.com/v1/models";
$headers = [
    "Authorization: Bearer $API_KEY"
];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http_code === 200) {
    $API_CHK = "✅有効なキーで認証に成功";
} else {
    $API_CHK = "❌無効なキーか認証に失敗";
}

// =============================
// SQLite 最最適化
// =============================
$db = new SQLite3('chat.db');
$db->exec("PRAGMA journal_mode=WAL;");
$db->exec("PRAGMA synchronous=NORMAL;");
$db->exec("CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL DEFAULT '新しい会話',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    hide_user_messages INTEGER DEFAULT 0
)");
$db->exec("ALTER TABLE shared_conversations ADD COLUMN hide_user_messages INTEGER DEFAULT 0");
$db->exec("CREATE TABLE IF NOT EXISTS shared_conversations (
    share_id TEXT PRIMARY KEY,
    conversation_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    hide_user_messages INTEGER DEFAULT 0
)");
$db->exec("ALTER TABLE shared_conversations ADD COLUMN hide_user_messages INTEGER DEFAULT 0");
$db->exec("CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER,
    role TEXT,
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    hide_user_messages INTEGER DEFAULT 0
)");
$db->exec("ALTER TABLE shared_conversations ADD COLUMN hide_user_messages INTEGER DEFAULT 0");

// =============================
// アクション処理
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $newPassword = trim($_POST['new_password'] ?? '');
        if ($newPassword !== '') {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
            $db->exec("INSERT OR REPLACE INTO settings(key,value) VALUES('basic_auth_password','".$db->escapeString($hash)."')");
        }
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    if ($action === 'new_conversation') {
        $db->exec("INSERT INTO conversations (title) VALUES ('新しい会話')");
        $newId = $db->lastInsertRowID();
        header("Location: ?cid=" . $newId);
        exit;
    }

    elseif ($action === 'delete_conversation') {
        $id = intval($_POST['id']);
        $db->exec("DELETE FROM conversations WHERE id=$id");
        $db->exec("DELETE FROM messages WHERE conversation_id=$id");
    }

    elseif ($action === 'edit_title') {
        $id = intval($_POST['id']);
        $title = $db->escapeString($_POST['title']);
        $db->exec("UPDATE conversations SET title='$title' WHERE id=$id");
    }

    // 最新のタイトルを非同期で取得するアクションを追加
    elseif ($action === 'get_title') {
        $conversation_id = intval($_POST['conversation_id']);
        $title = $db->querySingle("SELECT title FROM conversations WHERE id=$conversation_id");
        echo $title;
        exit;
    }


    elseif ($action === 'share_conversation') {

        $conversation_id = intval($_POST['conversation_id']);

        $existing = $db->querySingle(
            "SELECT share_id FROM shared_conversations WHERE conversation_id=$conversation_id"
        );

        $hide_questions = isset($_POST['hide_questions']) ? intval($_POST['hide_questions']) : 0;

        if (!$existing) {
            $existing = bin2hex(random_bytes(8));
            $shareEscaped = $db->escapeString($existing);
            $db->exec("INSERT INTO shared_conversations (share_id, conversation_id, hide_user_messages) VALUES ('$shareEscaped',$conversation_id,$hide_questions)");
        } else {
            $db->exec("UPDATE shared_conversations SET hide_user_messages=$hide_questions WHERE conversation_id=$conversation_id");
        }

        header('Content-Type: application/json');

        echo json_encode([
            "url" => ((isset($_SERVER['HTTPS']) ? "https" : "http")
            . "://"
            . $_SERVER['HTTP_HOST']
            . strtok($_SERVER['REQUEST_URI'], '?')
            . "?share="
            . $existing)
        ]);
        exit;
    }

    // ストリーミング用のアクション
    elseif ($action === 'stream_message') {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $conversation_id = intval($_POST['conversation_id']);
        $msg = trim($_POST['message']);

        if ($msg !== "") {
            $msgEscaped = $db->escapeString($msg);
            $db->exec("INSERT INTO messages (conversation_id, role, content) VALUES ($conversation_id, 'user', '$msgEscaped')");

            $res = $db->query("SELECT role, content FROM messages WHERE conversation_id=$conversation_id ORDER BY created_at ASC");
            $messages = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $messages[] = [
                    "role" => $row['role'],
                    "content" => $row['content']
                ];
            }

            $url = "https://api.openai.com/v1/chat/completions";
            $headers = [
                "Content-Type: application/json",
                "Authorization: Bearer " . $API_KEY
            ];
            $postData = json_encode([
                "model" => $MODEL,
                "messages" => $messages,
                "stream" => true
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            $full_reply = "";
            $buffer = "";

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$full_reply, &$buffer) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if (strpos($line, 'data: ') === 0) {
                        $json_str = substr($line, 6);
                        if ($json_str === '[DONE]') continue;

                        $json = json_decode($json_str, true);
                        if (isset($json['choices'][0]['delta']['content'])) {
                            $content = $json['choices'][0]['delta']['content'];
                            $full_reply .= $content;
                            echo $content;
                            if (function_exists('flush')) { flush(); }
                        }
                    }
                }
                return strlen($data);
            });

            curl_exec($ch);
            curl_close($ch);

            if ($full_reply !== "") {
                $replyEscaped = $db->escapeString($full_reply);
                $db->exec("INSERT INTO messages (conversation_id, role, content) VALUES ($conversation_id, 'assistant', '$replyEscaped')");
            }

            // === 新規チャットならタイトルを自動生成 ===
            $count = $db->querySingle("SELECT COUNT(*) FROM messages WHERE conversation_id=$conversation_id");
            if ($count <= 3) {
                $summaryPrompt = [
                    [
                        "role" => "system",
                        "content" => "以下の会話を20文字以内の短い日本語タイトルに要約してください。記号なしで自然なタイトルを出力してください。"
                    ],
                    [
                        "role" => "user",
                        "content" => implode("\n", array_map(fn($m) => "{$m['role']}: {$m['content']}", $messages))
                    ]
                ];

                $titleResponse = callOpenAI($API_KEY, $MODEL, $summaryPrompt);
                if ($titleResponse) {
                    $title = mb_substr(trim($titleResponse), 0, 30);
                    $titleEscaped = $db->escapeString($title);
                    $db->exec("UPDATE conversations SET title='$titleEscaped' WHERE id=$conversation_id");
                }
            }
        }
        exit;
    }
}

// =============================
// OpenAI API 呼び出し関数 (タイトル生成用)
// =============================
function callOpenAI($apiKey, $model, $messages) {
    $url = "https://api.openai.com/v1/chat/completions";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ];
    $postData = json_encode([
        "model" => $model,
        "messages" => $messages
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    $result = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($result, true);
    if (isset($json['choices'][0]['message']['content'])) {
        return $json['choices'][0]['message']['content'];
    }
    return false;
}

// =============================
// データ取得
// =============================
$conversations = $db->query("SELECT * FROM conversations ORDER BY created_at DESC");
if(isset($_GET['share'])){
    $share_id = $db->escapeString($_GET['share']);
    $current_conversation_id = $db->querySingle("SELECT conversation_id FROM shared_conversations WHERE share_id='$share_id'");
    $hideUserMessages = intval($db->querySingle("SELECT hide_user_messages FROM shared_conversations WHERE share_id='$share_id'"));
    $shareMode = true;
} else {
    $shareMode = false;
    $current_conversation_id = $_GET['cid'] ?? null;
}
$messages = [];
if ($current_conversation_id) {
    $res = $db->query("SELECT * FROM messages WHERE conversation_id=$current_conversation_id ORDER BY created_at ASC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $messages[] = $row;
    }
}
?>

<?php if(isset($shareMode) && $shareMode): ?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>共有された会話</title>
<style>
<style>
body{margin:0;background:#f7f7f8;font-family:sans-serif;}
.share-container{max-width:900px;margin:0 auto;padding:24px;}
.share-title{font-size:32px;margin-bottom:24px;}
.msg{margin:10px 0;padding:10px;border-radius:8px;max-width:85%;word-break:break-word;}
.msg.user{background:#3498db;color:white;margin-left:auto;}
.msg.assistant{background:white;border:1px solid #ccc;}

/* ▼ 共有ページ用のMarkdownスタイル ▼ */
.msg p { margin: 0 0 10px 0; }
.msg p:last-child { margin: 0; }
.msg pre { background: #282c34; color: #abb2bf; padding: 12px; border-radius: 6px; overflow-x: auto; margin: 10px 0; font-family: Consolas, Monaco, monospace; }
.msg code { background: rgba(0,0,0,0.1); padding: 2px 4px; border-radius: 4px; font-family: Consolas, Monaco, monospace; font-size: 0.9em; }
.msg pre code { background: transparent; padding: 0; color: inherit; }
.msg table { border-collapse: collapse; width: 100%; margin: 10px 0; }
.msg th, .msg td { border: 1px solid #bdc3c7; padding: 8px; }
.msg th { background: rgba(0,0,0,0.05); }
.msg ul, .msg ol { margin: 0 0 10px 0; padding-left: 20px; }
</style>
</head>
<body>
<div class="share-container">
<h1 class="share-title">
<?= htmlspecialchars($db->querySingle("SELECT title FROM conversations WHERE id=$current_conversation_id")) ?>
</h1>

<?php foreach ($messages as $msg): ?>
<?php if (!isset($hideUserMessages) || !$hideUserMessages || $msg['role'] !== 'user'): ?>
<div class="msg <?= $msg['role'] ?> markdown-text" style="display:none;">
<?= htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

</div>

<script>
async function shareConversation(conversationId){
 const fd = new FormData();
 fd.append("action","share_conversation");
 fd.append("conversation_id",conversationId);

 const r = await fetch(window.location.href,{
   method:"POST",
   body:fd
 });

 const j = await r.json();

 await navigator.clipboard.writeText(j.url);

 alert("共有URLをコピーしました\n\n" + j.url);
}
</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js@11.8.0/styles/atom-one-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/highlight.js@11.8.0/lib/highlight.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@9.1.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    if (typeof marked !== 'undefined') {
        marked.setOptions({
            breaks: true,
            highlight: function(code, lang) {
                if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language: lang }).value;
                }
                return code;
            }
        });

        // 共有ページ内の隠れているメッセージをMarkdown化して表示
        const markdownElements = document.querySelectorAll('.markdown-text');
        markdownElements.forEach(el => {
            const rawText = el.textContent || el.innerText;
            el.innerHTML = DOMPurify.sanitize(marked.parse(rawText));
            el.style.display = 'block'; // 変換が終わったら表示
            el.classList.remove('markdown-text');
        });
    }
});
</script>
<?php exit; endif; ?>

<!DOCTYPE html>
<html lang="ja-JP">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="cache-control" content="no-cache, no-store">
        <meta http-equiv="pragma" content="no-cache">
        <meta name="expires" content="0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#bbc8e6">
        <meta name="description" content="ChatGPT Web UI.">
        <meta name="keywords" content="chatgpt,api">
        <meta name="author" content="@reinforchu">
        <title>Rumoku - GPT WebUI</title>
<style>
*{
  box-sizing:border-box;
}

body{
  margin:0;
  font-family:sans-serif;
  display:flex;
  height:100vh;
  overflow:hidden;
}

/* ==========================
   Sidebar
========================== */

.sidebar{
  width:280px;
  min-width:280px;
  background:#2c3e50;
  color:#ecf0f1;
  padding:10px;
  overflow-y:auto;
}

.sidebar h2{
  font-size:18px;
  margin:0 0 10px;
}

.sidebar .conv{
  padding:8px;
  margin-bottom:5px;
  background:#34495e;
  border-radius:6px;
  cursor:pointer;
  overflow:hidden;
}

.sidebar .conv.active{
  background:#1abc9c;
}

.sidebar .conv span{
  display:block;
  font-weight:bold;
}

.sidebar .conv form{
  display:inline;
}

/* ==========================
   Chat Area
========================== */

.chat-area{
  flex:1;
  display:flex;
  flex-direction:column;
  background:#ecf0f1;
  min-width:0;
}

.chat-header{
  padding:10px;
  background:#bdc3c7;
  display:flex;
  align-items:center;
  justify-content:space-between;
}

.chat-header h2{
  margin:0;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

.chat-header input{
  font-size:18px;
  padding:4px;
  border:none;
  background:transparent;
  border-bottom:1px solid #333;
  width:70%;
}

.chat-messages{
  flex:1;
  overflow-y:auto;
  padding:15px;
}

/* ==========================
   Messages
========================== */

.msg{
  margin:10px 0;
  padding:10px;
  border-radius:8px;
  max-width:70%;
  word-break:break-word;
  overflow-wrap:anywhere;
}

.msg.user{
  background:#3498db;
  color:white;
  margin-left:auto;
}

.msg.assistant{
  background:white;
  color:black;
  margin-right:auto;
  border:1px solid #ccc;
}

/* ==========================
   Input Area
========================== */

.chat-input{
  padding:10px;
  background:#bdc3c7;
  display:flex;
}

.chat-input input{
  flex:1;
  padding:8px;
  border-radius:8px;
  border:1px solid #aaa;
}

.chat-input button{
  margin-left:10px;
  padding:8px 16px;
  border:none;
  border-radius:8px;
  background:#1abc9c;
  color:white;
  cursor:pointer;
}

.chat-input textarea:focus{
  outline:none;
  border-color:#1abc9c;
  box-shadow:0 0 4px rgba(26,188,156,.4);
}

/* ==========================
   Mobile
========================== */

@media (max-width:768px){

  body{
    flex-direction:column;
    height:100dvh;
  }

  .sidebar{
    width:100%;
    min-width:100%;
    max-height:35vh;
    overflow-y:auto;
    padding:8px;
  }

  .chat-area{
    flex:1;
    min-height:0;
  }

  .chat-header{
    padding:8px;
  }

  .chat-header h2{
    font-size:16px;
  }

  .chat-messages{
    padding:10px;
  }

  .msg{
    max-width:92%;
    font-size:15px;
  }

  .conv button{
    margin-top:4px;
    margin-bottom:4px;
  }

  .chat-input{
    padding:8px;
  }

  #chatForm{
    width:100%;
  }

  #chatMessage{
    font-size:16px;
  }

  .chat-input button{
    padding:10px 14px;
    font-size:15px;
    white-space:nowrap;
  }

  #editModal > div{
    width:90%;
    min-width:auto !important;
  }
}

/* ==========================
   Small Mobile
========================== */

@media (max-width:480px){

  .sidebar{
    max-height:30vh;
  }

  .msg{
    max-width:96%;
    font-size:14px;
  }

  .chat-header h2{
    font-size:15px;
  }

  .chat-input button{
    padding:10px;
  }
}

/* ==========================
   Markdown Styles
========================== */
.msg p { margin: 0 0 10px 0; }
.msg p:last-child { margin: 0; }
.msg pre { 
  background: #282c34; 
  color: #abb2bf; 
  padding: 12px; 
  border-radius: 6px; 
  overflow-x: auto; 
  margin: 10px 0;
  font-family: Consolas, Monaco, monospace;
}
.msg code { 
  background: rgba(0,0,0,0.1); 
  padding: 2px 4px; 
  border-radius: 4px; 
  font-family: Consolas, Monaco, monospace; 
  font-size: 0.9em;
}
.msg pre code { 
  background: transparent; 
  padding: 0; 
  color: inherit;
}
.msg table { 
  border-collapse: collapse; 
  width: 100%; 
  margin: 10px 0; 
}
.msg th, .msg td { 
  border: 1px solid #bdc3c7; 
  padding: 8px; 
}
.msg th { background: rgba(0,0,0,0.05); }
.msg ul, .msg ol { margin: 0 0 10px 0; padding-left: 20px; }

</style>
</head>
<body>
<div class="sidebar">
  <h1>Rumoku</h1>
  <form method="post">
    <input type="hidden" name="action" value="new_conversation">
    <button style="width:100%; padding:8px; background:#1abc9c; border:none; color:white; border-radius:6px; cursor:pointer;">💬  新しいチャットの開始</button>
  </form>

  <button id="changePasswordBtn" type="button"
    style="width:100%;margin-top:8px;padding:8px;background:#6a5acd;border:none;color:white;border-radius:6px;cursor:pointer;">
    🔑  パスワード変更
  </button>

  <hr>
  <h2>ステータス</h2>
  <small>認証：<?php echo "{$API_CHK}"; ?><br>モデル：<?php echo "{$MODEL}"; ?><br>Version 1.2.1 / @reinforchu</small>
  <hr>
  <h2>チャット履歴</h2>
<?php while($row = $conversations->fetchArray(SQLITE3_ASSOC)): ?>
  <div class="conv <?= ($row['id']==$current_conversation_id)?'active':'' ?>" onclick="event.stopPropagation();window.location.href='?cid=<?= $row['id'] ?>'">
    <a href="?cid=<?= $row['id'] ?>" style="color:white; text-decoration:none;"><small class="sidebar-title-<?= $row['id'] ?>"><?= htmlspecialchars($row['title']) ?></small></a>
<hr>
    <button onclick="event.stopPropagation();window.location.href='?cid=<?= $row['id'] ?>'" style="background:#FF8C00; color:white; border:none; border-radius:4px; cursor:pointer;">再開する</button>

    <button id="editBtn-<?= $row['id'] ?>" style="background:#2E8B57; color:white; border:none; border-radius:4px; cursor:pointer;" data-title="<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>" onclick="event.stopPropagation();openEditModal(<?= $row['id'] ?>, this.getAttribute('data-title'))">名前変更</button>

<form method="post" style="display:inline;">
  <input type="hidden" name="action" value="delete_conversation">
  <input type="hidden" name="id" value="<?= $row['id'] ?>">
  <button type="button"
        class="share-btn"
        data-cid="<?= $row['id'] ?>"
        style="background:#4169E1; color:white; border:none; border-radius:4px; cursor:pointer;">共有</button>
  <button style="background:#DC143C; color:white; border:none; border-radius:4px; cursor:pointer;">削除</button>
</form>
<br>
<small><?= htmlspecialchars($row['created_at']) ?></small>
  </div>
<?php endwhile; ?>
</div>
<div class="chat-area">
  <?php if ($current_conversation_id): ?>
<div class="chat-header">
    <?php
    if ($current_conversation_id) {
        $currentTitle = $db->querySingle("SELECT title FROM conversations WHERE id=$current_conversation_id");
        // リアルタイム更新用に、h2タグに id="currentChatTitle" を追加
        echo '<h2 id="currentChatTitle" style="margin:0; font-size:20px;">💬 ' . htmlspecialchars($currentTitle) . '</h2>';
    } else {
        echo '<h2 style="margin:0; font-size:20px;">💬  チャットを選択してください</h2>';
    }
    ?>
<?php if(!$shareMode): ?>
<div style="display:flex; gap:8px;">
  <button id="downloadImgBtn" type="button" style="background:#8e44ad;color:white;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;">📷 画像保存</button>
  <button id="shareBtn" type="button" style="background:#4169E1;color:white;border:none;border-radius:6px;padding:6px 12px;cursor:pointer;">📎 共有</button>
</div>
<?php endif; ?>
</div>
  <div class="chat-messages">
    <?php foreach ($messages as $msg): ?>
      <div class="msg <?= $msg['role'] ?> markdown-text" style="display:none;"><?= htmlspecialchars($msg['content'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<div class="chat-input">
  <form method="post" id="chatForm" style="display:flex; width:100%; flex-direction:row; align-items:flex-end;">
    <input type="hidden" name="action" value="stream_message">
    <input type="hidden" name="conversation_id" value="<?= $current_conversation_id ?>">

    <textarea id="chatMessage" name="message" placeholder="何か質問はありますか？（Enterで改行します）" rows="1" style="flex:1; resize:none; padding:10px; border-radius:12px; border:1px solid #aaa; font-size:16px; line-height:1.5; max-height:200px; overflow-y:auto;"></textarea>
    <button type="submit" id="sendBtn" style="background:#1abc9c; color:white; border:none; border-radius:12px; padding:10px 16px; margin-left:8px; cursor:pointer; font-size:16px;">⬆️  送信</button>
  </form>
</div>
  <?php else: ?>
    <div style="margin:auto; font-size:20px; color:#555;">💬  チャットを始めましょう！</div>
  <?php endif; ?>
</div>

<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; 
    background: rgba(0,0,0,0.5); justify-content:center; align-items:center;">
  <div style="background:white; padding:20px; border-radius:8px; min-width:300px; position:relative;">
    <h3>チャットタイトルを変更</h3>
    <form method="post">
      <input type="hidden" name="action" value="edit_title">
      <input type="hidden" name="id" id="editConversationId">
      <input type="text" name="title" id="editTitle" style="width:100%; padding:6px; margin:10px 0;">
      <button type="submit" style="background:#1abc9c; color:white; border:none; border-radius:6px; padding:6px 12px;">保存</button>
      <button type="button" style="background:#ccc; color:black; border:none; border-radius:6px; padding:6px 12px; margin-left:5px;" onclick="closeEditModal()">キャンセル</button>
    </form>
  </div>
</div>

<script>
function openEditModal(id, title) {
    document.getElementById('editConversationId').value = id;
    document.getElementById('editTitle').value = title;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}
</script>

<div id="overlay" style="
  display:none;
  position:fixed;
  top:0; left:0;
  width:100%; height:100%;
  background:rgba(0,0,0,0.4);
  z-index:9999;
  justify-content:center;
  align-items:center;
  backdrop-filter:blur(4px);
  flex-direction:column;
  color:white;
  text-align:center;
">
  <div class="spinner" style="
      width:60px; height:60px;
      border:6px solid rgba(255,255,255,0.3);
      border-top-color:white;
      border-radius:50%;
      animation:spin 1s linear infinite;
      margin-bottom:20px;
  "></div>
  <div style="font-size:18px;">⏳ 処理中です...<br>しばらくお待ちください</div>
</div>

<style>
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

@media (max-width: 600px) {
  #overlay div {
    font-size: 16px;
  }
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const overlay = document.getElementById("overlay");
  const forms = document.querySelectorAll("form");

  forms.forEach(form => {
    const actionInput = form.querySelector('input[name="action"]');
    if (!actionInput) return;

    const action = actionInput.value;

    if (action === "delete_conversation") {
      form.addEventListener("submit", function (e) {
        const ok = confirm("本当に削除しますか？");
        if (!ok) {
          e.preventDefault();
          if (overlay) overlay.style.display = "none";
          return;
        }
        if (overlay) overlay.style.display = "flex";
      });
      return;
    }

    if (form.id === "chatForm") {
        return; 
    }

    form.addEventListener("submit", function (e) {
      if (overlay) overlay.style.display = "flex";
    });
  });
});
</script>

<script>
window.addEventListener('pageshow', () => {
  const overlay = document.getElementById('overlay');
  if (overlay) overlay.style.display = 'none';
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const chatMessages = document.querySelector(".chat-messages");
  if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
  }
});
</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const textarea = document.getElementById("chatMessage");
  const form = document.getElementById("chatForm");

  if(textarea) {
      textarea.addEventListener("input", function () {
        this.style.height = "auto";
        this.style.height = this.scrollHeight + "px";
      });

      textarea.addEventListener("keydown", function (e) {
        if ((e.key === "Enter" || e.keyCode === 13) && (e.ctrlKey || e.metaKey)) {
          e.preventDefault();
          form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
        }
      });
  }

  if(form) {
      form.addEventListener("submit", async function(e) {
          e.preventDefault();

          const messageInput = document.getElementById("chatMessage");
          const sendBtn = document.getElementById("sendBtn");
          const messageText = messageInput.value.trim();

          if (!messageText) return;

          messageInput.value = "";
          messageInput.style.height = "auto";
          sendBtn.disabled = true;
          sendBtn.style.opacity = "0.6";

          const escapeHTML = (str) => str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');

          const chatMessages = document.querySelector(".chat-messages");
          const userMsgDiv = document.createElement("div");
          userMsgDiv.className = "msg user";
          userMsgDiv.innerHTML = DOMPurify.sanitize(marked.parse(messageText));
          chatMessages.appendChild(userMsgDiv);
          chatMessages.scrollTop = chatMessages.scrollHeight;

          const assistantMsgDiv = document.createElement("div");
          assistantMsgDiv.className = "msg assistant";
          assistantMsgDiv.innerHTML = "…";
          chatMessages.appendChild(assistantMsgDiv);
          chatMessages.scrollTop = chatMessages.scrollHeight;

          const formData = new FormData(form);
          formData.set("message", messageText);

          try {
              const response = await fetch(window.location.href, {
                  method: 'POST',
                  body: formData
              });

              if (!response.body) throw new Error('ReadableStream not supported');

              const reader = response.body.getReader();
              const decoder = new TextDecoder("utf-8");
              let done = false;
              let fullText = "";
              let isFirstChunk = true;

              while (!done) {
                  const { value, done: readerDone } = await reader.read();
                  done = readerDone;
                  if (value) {
                      const chunk = decoder.decode(value, { stream: true });
                      fullText += chunk;
                      
                      if (isFirstChunk && chunk.trim() !== "") {
                          assistantMsgDiv.innerHTML = "";
                          isFirstChunk = false;
                      }
                      
                      assistantMsgDiv.innerHTML = DOMPurify.sanitize(marked.parse(fullText));
                      chatMessages.scrollTop = chatMessages.scrollHeight;
                  }
              }

              sendBtn.disabled = false;
              sendBtn.style.opacity = "1";

              // === 【追加】ストリーミング完了後に、最新タイトルを非同期で取得・随時更新する処理 ===
              const titleFormData = new FormData();
              titleFormData.append("action", "get_title");
              titleFormData.append("conversation_id", "<?= $current_conversation_id ?>");

              const titleResponse = await fetch(window.location.href, {
                  method: 'POST',
                  body: titleFormData
              });

              if (titleResponse.ok) {
                  const newTitle = await titleResponse.text();
                  if (newTitle.trim() !== "") {
                      // 1. チャットエリア上のヘッダータイトルを更新
                      const headerTitle = document.getElementById("currentChatTitle");
                      if (headerTitle) {
                          headerTitle.innerHTML = "💬 " + escapeHTML(newTitle);
                      }
                      // 2. サイドバー内の該当チャット履歴のタイトルテキストを更新
                      const sidebarTitle = document.querySelector(".sidebar-title-<?= $current_conversation_id ?>");
                      if (sidebarTitle) {
                          sidebarTitle.textContent = newTitle;
                      }
                      // 3. サイドバー内の「名前変更」ボタンにセットされているdata属性も更新
                      const editBtn = document.getElementById("editBtn-<?= $current_conversation_id ?>");
                      if (editBtn) {
                          editBtn.setAttribute("data-title", newTitle);
                      }
                  }
              }

          } catch (error) {
              console.error("Fetch error:", error);
              assistantMsgDiv.innerHTML = "<span style='color:red;'>[通信エラーが発生しました]</span>";
              sendBtn.disabled = false;
              sendBtn.style.opacity = "1";
          }
      });
  }
});
</script>



<script>
document.querySelectorAll('.share-btn').forEach(btn => {
    btn.addEventListener('click', async function(e){
        e.preventDefault();
        e.stopPropagation();

        try{
            const fd = new FormData();
            fd.append('action','share_conversation');
            fd.append('conversation_id', this.dataset.cid);
            const hideQuestions = confirm('共有時に質問内容を非表示にしますか？\nOK=非表示 / キャンセル=表示');
            fd.append('hide_questions', hideQuestions ? '1' : '0');

            const response = await fetch(window.location.pathname, {
                method: 'POST',
                body: fd
            });

            const json = await response.json();

            await navigator.clipboard.writeText(json.url);

            alert('共有URLをコピーしました\n\n' + json.url);
        }catch(err){
            console.error(err);
            alert('共有URLの生成に失敗しました');
        }
    });
});
</script>

<script>
const shareBtn=document.getElementById("shareBtn");
if(shareBtn){
shareBtn.addEventListener("click",async()=>{
 const fd=new FormData();
 fd.append("action","share_conversation");
 fd.append("conversation_id","<?= $current_conversation_id ?>");
 const hideQuestions=confirm("共有時に質問内容を非表示にしますか？\nOK=非表示 / キャンセル=表示");
 fd.append("hide_questions",hideQuestions?"1":"0");
 const r=await fetch(window.location.href,{method:"POST",body:fd});
 const j=await r.json();
 await navigator.clipboard.writeText(j.url);
 alert("共有URLをクリップボードにコピーしました\n\n"+j.url);
});
}
</script>


<script>
document.addEventListener('DOMContentLoaded', function(){
    const btn = document.getElementById('changePasswordBtn');
    const modal = document.getElementById('passwordModal');

    if(btn && modal){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            modal.style.display = 'flex';
        });

        modal.addEventListener('click', function(e){
            if(e.target === modal){
                modal.style.display = 'none';
            }
        });
    }
});
</script>


<div id="passwordModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:9999;">
  <form method="post" style="background:white;padding:20px;border-radius:8px;min-width:300px;">
    <input type="hidden" name="action" value="change_password">
    <h3>パスワード変更</h3>
    <input type="password" name="new_password" placeholder="新しいパスワード" required style="width:100%;padding:8px;">
    <br><br>
    <button type="submit">保存</button>
    <button type="button" onclick="document.getElementById('passwordModal').style.display='none';">キャンセル</button>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function(){
 const btn=document.getElementById('changePasswordBtn');
 const modal=document.getElementById('passwordModal');
 if(btn&&modal){
   btn.onclick=function(e){e.preventDefault();modal.style.display='flex';};
   modal.onclick=function(e){if(e.target===modal) modal.style.display='none';};
 }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const downloadImgBtn = document.getElementById('downloadImgBtn');
    if (downloadImgBtn) {
        downloadImgBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            const chatMessages = document.querySelector('.chat-messages');
            if (!chatMessages) return;

            // html2canvas が読み込まれているかチェック
            if (typeof html2canvas === 'undefined') {
                alert('画像生成ライブラリの読み込みに失敗しました。\n広告ブロック機能などがオンになっている場合は一時的にオフにしてみてください。');
                return;
            }

            const overlay = document.getElementById('overlay');
            if (overlay) overlay.style.display = 'flex';

            // フリーズ対策：描画の前に一呼吸おく
            await new Promise(resolve => setTimeout(resolve, 150));

            const originalOverflow = chatMessages.style.overflowY;
            const originalHeight = chatMessages.style.height;
            const originalScrollTop = chatMessages.scrollTop;

            try {
                // スクロール領域を展開
                chatMessages.style.overflowY = 'visible';
                chatMessages.style.height = chatMessages.scrollHeight + 'px';

                // html2canvas で画像化
                const canvas = await html2canvas(chatMessages, {
                    backgroundColor: '#ecf0f1',
                    scale: window.devicePixelRatio || 2,
                    useCORS: true,
                    logging: false
                });

                // スタイルを元に戻す
                chatMessages.style.overflowY = originalOverflow;
                chatMessages.style.height = originalHeight;
                chatMessages.scrollTop = originalScrollTop;

                // 処理中画面を非表示
                if (overlay) overlay.style.display = 'none';
                
                // ダウンロード実行
                const link = document.createElement('a');
                const date = new Date();
                const filename = 'chat_' + date.getFullYear() + 
                                 (date.getMonth() + 1).toString().padStart(2, '0') + 
                                 date.getDate().toString().padStart(2, '0') + '_' + 
                                 date.getHours().toString().padStart(2, '0') + 
                                 date.getMinutes().toString().padStart(2, '0') + '.png';
                link.download = filename;
                link.href = canvas.toDataURL('image/png');
                link.click();

            } catch (error) {
                // エラー時も確実に元に戻す
                chatMessages.style.overflowY = originalOverflow;
                chatMessages.style.height = originalHeight;
                chatMessages.scrollTop = originalScrollTop;
                if (overlay) overlay.style.display = 'none';
                
                console.error("画像保存エラー:", error);
                alert("画像の保存に失敗しました。");
            }
        });
    }
});
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js@11.8.0/styles/atom-one-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/highlight.js@11.8.0/lib/highlight.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@9.1.2/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    // marked.js の設定（改行の許可とシンタックスハイライトの有効化）
    if (typeof marked !== 'undefined') {
        marked.setOptions({
            breaks: true,
            highlight: function(code, lang) {
                if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language }).value;
                }
                return code; // 言語指定がない場合はそのまま
            }
        });

        // ページ読み込み時に過去のメッセージをMarkdown化して表示
        const markdownElements = document.querySelectorAll('.markdown-text');
        markdownElements.forEach(el => {
            const rawText = el.textContent || el.innerText;
            // XSS対策としてDOMPurifyを通してから挿入
            el.innerHTML = DOMPurify.sanitize(marked.parse(rawText));
            el.style.display = 'block'; // 隠していたものを表示
            el.classList.remove('markdown-text');
        });
    }
});
</script>
</body>
</html>