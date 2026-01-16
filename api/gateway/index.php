<?php
/*
  api/gateway/index.php
  GhostsPay integration for Hostinger (PHP)
  - acao=criar      -> cria transação via GhostsPay (POST /transactions)
  - acao=verificar  -> verifica status (GET /transactions/{id})
  - acao=webhook    -> endpoint para GhostsPay (POST) -> atualiza payments.json
  - acao=simular    -> altera status manual (APENAS para testes)

  IMPORTANT:
  - Preencha GHOST_SECRET_KEY e GHOST_COMPANY_ID abaixo (no servidor).
  - Coloque YOUR_DOMAIN_HERE no postbackUrl (ex: https://seudominio.com).
  - Configure GhostsPay webhook para: https://YOUR_DOMAIN_HERE/api/gateway?acao=webhook
*/

// --- HEADERS ---
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *"); // ajuste em produção
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ---------------------------
// CONFIGURE AQUI (EDITE NO SERVIDOR)
// ---------------------------
// NÃO COLE ESSAS CHAVES EM LUGARES PÚBLICOS
define('GHOST_SECRET_KEY', 'sk_live_Os3Eecd8G9jgt75ubIOuJeR82fXyfa2ElKFWrWsy3c3EXjLo'); // ex: sk_live_...
define('GHOST_COMPANY_ID', 'db9ac3e5-c99b-476f-a454-6df8c06e2883'); // ex: db9ac3e5-...
define('GHOST_BASE', 'https://api.ghostspaysv2.com/functions/v1');
define('POSTBACK_BASE_URL', 'https://recargajogo-delta.vercel.app'); // ex: https://meusite.com

// local fallback Pix (se desejar)
$LOCAL_PIX_KEY = 'SUA_CHAVE_PIX_AQUI';
$LOCAL_MERCHANT_NAME = 'NOME DO RECEBEDOR';
$LOCAL_MERCHANT_CITY = 'CIDADE';

// arquivo local para salvar pagamentos
$DATA_FILE = __DIR__ . '/payments.json';
if (!file_exists($DATA_FILE)) file_put_contents($DATA_FILE, json_encode([], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// ---------------------------
// HELPERS
// ---------------------------
function readPayments($file) {
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function writePayments($file, $data) {
    $fp = fopen($file, 'c+');
    if ($fp === false) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
// http request helper (cURL)
function http_request($url, $method='GET', $body=null, $headers=[]) {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
    ];
    if (($method === 'POST' || $method === 'PUT') && $body !== null) {
        $json = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_POSTFIELDS] = $json;
        // ensure content-type default
        $hasCT = false;
        foreach ($headers as $h) if (stripos($h, 'content-type:') === 0) $hasCT = true;
        if (!$hasCT) $headers[] = 'Content-Type: application/json';
    }
    if (!empty($headers)) $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $err === '', 'code' => $code, 'body' => $resp, 'error' => $err];
}
function base64_basic_auth($secret, $company) {
    return base64_encode($secret . ':' . $company);
}
function get_nested($arr, $path) {
    if ($path === '' || $path === null) return null;
    if (!is_array($arr)) return null;
    $p = explode('.', $path);
    $cur = $arr;
    foreach ($p as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    return $cur;
}
function extract_pix_from_body($bodyArr) {
    $candidates = [
        'data.pix.qrcode', 'data.pix.qr', 'data.pix.payload', 'data.pix.end2EndId',
        'data.payload','data.qr','pix','payload','qr','copia_cola','copiaCola','pix_code','pixCode'
    ];
    foreach ($candidates as $c) {
        $v = get_nested($bodyArr, $c);
        if ($v) return $v;
    }
    return null;
}

// EMV helpers (fallback local)
function byte_length($s) { return mb_strlen($s, '8bit'); }
function tlv($id, $val) { return $id . str_pad(byte_length($val), 2, '0', STR_PAD_LEFT) . $val; }
function crc16($payload){ $crc = 0xFFFF; $len=strlen($payload); for($i=0;$i<$len;$i++){ $crc ^= (ord($payload[$i])<<8); for($j=0;$j<8;$j++){ if($crc & 0x8000) $crc = (($crc<<1)&0xFFFF) ^ 0x1021; else $crc = ($crc<<1)&0xFFFF; } } return strtoupper(str_pad(dechex($crc & 0xFFFF),4,'0',STR_PAD_LEFT)); }
function gerarPixLocal($key,$name,$city,$valor,$txid){ $payload=''; $payload .= tlv('00','01'); $payload .= tlv('26', tlv('00','BR.GOV.BCB.PIX') . tlv('01',$key)); $payload .= tlv('52','0000'); $payload .= tlv('53','986'); if($valor!==null && $valor!=='' && floatval($valor)>0) $payload .= tlv('54', number_format(floatval($valor),2,'.','')); $payload .= tlv('58','BR'); $payload .= tlv('59', mb_substr($name,0,25,'UTF-8')); $payload .= tlv('60', mb_substr($city,0,15,'UTF-8')); if($txid) $payload .= tlv('62', tlv('05',$txid)); $payload .= '6304'; $payload .= crc16($payload); return $payload; }

// ---------------------------
// ROTEAMENTO
// ---------------------------
$acao = strtolower(trim($_REQUEST['acao'] ?? ''));

// ---------- CREATE ----------
if ($acao === 'criar') {
    // params
    $valor = $_REQUEST['valor'] ?? ($_REQUEST['value'] ?? '0'); // valor em reais (ex: "36.72")
    $nome = $_REQUEST['nome'] ?? 'Cliente';
    $email = $_REQUEST['email'] ?? '';
    $cpf = $_REQUEST['cpf'] ?? null;
    $telefone = $_REQUEST['telefone'] ?? '';
    $up = $_REQUEST['up'] ?? '';
    $utm = $_REQUEST['utm'] ?? '';

    // ids locais
    $local_id = 'TX' . time() . rand(1000,9999);
    $txid = 'T' . substr(sha1($local_id . $nome . microtime()), 0, 12);

    // monta body para GhostsPay
    // amount em centavos (integer)
    $amount_cents = intval(round(floatval($valor) * 100));
    if ($amount_cents < 100) $amount_cents = max(100, $amount_cents); // mínimo 100

    // items: obrigatórios
    $items = [[
        'title' => 'Compra',
        'unitPrice' => $amount_cents,
        'quantity' => 1,
        'externalRef' => $local_id
    ]];

    $postbackUrl = rtrim(POSTBACK_BASE_URL, '/') . '/api/gateway?acao=webhook';

    $body = [
        'customer' => [
            'name' => $nome,
            'email' => $email ?: null,
            'phone' => $telefone ?: null,
            'document' => $cpf ?: null
        ],
        'items' => $items,
        'amount' => $amount_cents,
        'paymentMethod' => 'PIX',
        'postbackUrl' => $postbackUrl,
        'description' => "Pagamento via PIX - " . ($nome ?: ''),
        'metadata' => [
            'local_id' => $local_id,
            'txid' => $txid,
            'up' => $up,
            'utm' => $utm
        ]
    ];

    // auth header (Basic)
    $basic = base64_basic_auth(GHOST_SECRET_KEY, GHOST_COMPANY_ID);
    $headers = [
        'Authorization: Basic ' . $basic,
        'Content-Type: application/json'
    ];

    $url = GHOST_BASE . '/transactions';
    $resp = http_request($url, 'POST', $body, $headers);

    // se gateway falhar, faz fallback local
    if (!$resp['ok'] || ($resp['code'] >= 400 && $resp['code'] != 201)) {
        $pix = gerarPixLocal($LOCAL_PIX_KEY, $LOCAL_MERCHANT_NAME, $LOCAL_MERCHANT_CITY, $valor, $txid);
        $payments = readPayments($DATA_FILE);
        $payments[$local_id] = [
            'local_id' => $local_id,
            'gateway_id' => null,
            'txid' => $txid,
            'pixCode' => $pix,
            'valor' => number_format(floatval($valor),2,'.',''),
            'nome' => $nome,
            'status' => 'pending',
            'gateway_error' => $resp['error'] ?? $resp['body'],
            'created_at' => date(DATE_ATOM)
        ];
        writePayments($DATA_FILE, $payments);
        echo json_encode(['pixCode'=>$pix,'payment_id'=>$local_id,'mode'=>'fallback']);
        exit;
    }

    // decodifica retorno
    $bodyResp = json_decode($resp['body'], true);
    if (!is_array($bodyResp)) {
        // fallback local
        $pix = gerarPixLocal($LOCAL_PIX_KEY, $LOCAL_MERCHANT_NAME, $LOCAL_MERCHANT_CITY, $valor, $txid);
        $payments = readPayments($DATA_FILE);
        $payments[$local_id] = [
            'local_id' => $local_id,
            'gateway_id' => null,
            'txid' => $txid,
            'pixCode' => $pix,
            'valor' => number_format(floatval($valor),2,'.',''),
            'nome' => $nome,
            'status' => 'pending',
            'gateway_raw' => $resp['body'],
            'created_at' => date(DATE_ATOM)
        ];
        writePayments($DATA_FILE, $payments);
        echo json_encode(['pixCode'=>$pix,'payment_id'=>$local_id,'mode'=>'fallback_non_json']);
        exit;
    }

    // extrai gateway id e pix info
    $gateway_id = get_nested($bodyResp, 'data.id') ?? get_nested($bodyResp, 'id') ?? null;
    $pixExtract = extract_pix_from_body($bodyResp);

    // se não encontrou pix, tente outros campos comuns
    if (!$pixExtract) {
        $pixExtract = get_nested($bodyResp, 'data.pix.qrcode') ?? get_nested($bodyResp, 'data.pix.end2EndId') ?? get_nested($bodyResp, 'data.pix') ?? null;
    }

    // se ainda nada -> deixamos vazio e salvamos gateway response
    $payments = readPayments($DATA_FILE);
    $payments[$local_id] = [
        'local_id' => $local_id,
        'gateway_id' => $gateway_id,
        'txid' => $txid,
        'pixCode' => $pixExtract,
        'valor' => number_format(floatval($valor),2,'.',''),
        'nome' => $nome,
        'gateway_response' => $bodyResp,
        'status' => (get_nested($bodyResp,'data.status') ?? get_nested($bodyResp,'status') ?? 'pending'),
        'created_at' => date(DATE_ATOM)
    ];
    writePayments($DATA_FILE, $payments);

    echo json_encode(['pixCode' => $pixExtract, 'payment_id' => $local_id, 'gateway_id' => $gateway_id]);
    exit;
}

// ---------- VERIFY ----------
if ($acao === 'verificar') {
    $payment_id = $_REQUEST['payment_id'] ?? '';
    if (!$payment_id) { http_response_code(400); echo json_encode(['erro'=>true,'mensagem'=>'payment_id ausente']); exit; }

    $payments = readPayments($DATA_FILE);
    if (!isset($payments[$payment_id])) { http_response_code(404); echo json_encode(['erro'=>true,'mensagem'=>'payment_id nao encontrado']); exit; }

    $entry = $payments[$payment_id];
    $gateway_id = $entry['gateway_id'] ?? null;

    if ($gateway_id) {
        // chama GET /transactions/{id}
        $url = GHOST_BASE . '/transactions/' . urlencode($gateway_id);
        $basic = base64_basic_auth(GHOST_SECRET_KEY, GHOST_COMPANY_ID);
        $headers = ['Authorization: Basic ' . $basic, 'Content-Type: application/json'];
        $resp = http_request($url, 'GET', null, $headers);
        if ($resp['ok'] && $resp['code'] < 400) {
            $bodyResp = json_decode($resp['body'], true);
            $status = get_nested($bodyResp,'data.status') ?? get_nested($bodyResp,'status') ?? 'pending';
            // map GhostsPay statuses to front-friendly ones if needed
            // paid -> approved, waiting_payment -> pending, refused->refused, canceled->cancelled
            $map = [
                'paid'=>'approved','waiting_payment'=>'pending','refused'=>'refused','canceled'=>'cancelled',
                'refunded'=>'refunded','failed'=>'failed','expired'=>'expired','in_analisys'=>'in_analisys','in_protest'=>'in_protest'
            ];
            $status_mapped = $map[strtolower($status)] ?? $status;

            // save
            $payments[$payment_id]['status'] = $status_mapped;
            $payments[$payment_id]['gateway_response_verify'] = $bodyResp;
            $payments[$payment_id]['updated_at'] = date(DATE_ATOM);
            writePayments($DATA_FILE, $payments);

            echo json_encode(['payment_id'=>$payment_id,'status'=>$status_mapped,'raw_status'=>$status]);
            exit;
        } else {
            // fallback: return local status
            echo json_encode(['payment_id'=>$payment_id,'status'=>$entry['status'] ?? 'pending','warning'=>'verify_failed']);
            exit;
        }
    } else {
        // no gateway id -> just return local
        echo json_encode(['payment_id'=>$payment_id,'status'=>$entry['status'] ?? 'pending','pixCode'=>$entry['pixCode'] ?? null]);
        exit;
    }
}

// ---------- WEBHOOK ----------
if ($acao === 'webhook') {
    // only POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['erro'=>true,'mensagem'=>'Método inválido']); exit; }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if ($body === null) {
        parse_str($raw, $body);
    }
    // basic validation: should contain data.id and data.status per docs
    $data = $body['data'] ?? null;
    if (!$data) {
        // save raw for debugging
        $payments = readPayments($DATA_FILE);
        $payments['_webhook_last_raw'] = ['body'=>$body,'received_at'=>date(DATE_ATOM)];
        writePayments($DATA_FILE, $payments);
        echo json_encode(['ok'=>true,'warning'=>'no data']);
        exit;
    }
    $gateway_id = $data['id'] ?? null;
    $status = $data['status'] ?? null;
    $pix_info = $data['pix'] ?? ($data['payload'] ?? null);

    if (!$gateway_id || !$status) {
        // still save raw
        $payments = readPayments($DATA_FILE);
        $payments['_webhook_last_raw'] = ['body'=>$body,'received_at'=>date(DATE_ATOM)];
        writePayments($DATA_FILE, $payments);
        echo json_encode(['ok'=>true,'warning'=>'incomplete']);
        exit;
    }

    // open payments and try to find by gateway_id or txid
    $payments = readPayments($DATA_FILE);
    $foundKey = null;
    if (isset($payments[$gateway_id])) $foundKey = $gateway_id; // unlikely because keys are local_id
    else {
        foreach ($payments as $k=>$v) {
            if (!is_array($v)) continue;
            if (($v['gateway_id'] ?? '') === $gateway_id || ($v['txid'] ?? '') === $data['txid'] ?? '' || ($v['local_id'] ?? '') === ($data['local_id'] ?? '')) {
                $foundKey = $k; break;
            }
        }
    }

    if (!$foundKey) {
        // create minimal record
        $newKey = 'TX' . time() . rand(1000,9999);
        $payments[$newKey] = [
            'local_id' => $newKey,
            'gateway_id' => $gateway_id,
            'txid' => $data['txid'] ?? null,
            'pixCode' => $pix_info ?? null,
            'valor' => $data['amount'] ?? null,
            'nome' => $data['customer']['name'] ?? null,
            'status' => $status,
            'gateway_response_webhook' => $body,
            'created_at' => date(DATE_ATOM)
        ];
        writePayments($DATA_FILE, $payments);
        echo json_encode(['ok'=>true,'saved_new'=>true,'key'=>$newKey]);
        exit;
    }

    // update existing
    $payments[$foundKey]['status'] = $status;
    if ($pix_info) $payments[$foundKey]['pixCode'] = $pix_info;
    $payments[$foundKey]['gateway_response_webhook'] = $body;
    $payments[$foundKey]['updated_at'] = date(DATE_ATOM);
    writePayments($DATA_FILE, $payments);

    // respond 200 quickly
    echo json_encode(['ok'=>true,'updated'=>$foundKey]);
    exit;
}

// ---------- SIMULAR (teste) ----------
if ($acao === 'simular') {
    $payment_id = $_REQUEST['payment_id'] ?? '';
    $status = $_REQUEST['status'] ?? '';
    $allowed = ['pending','approved','cancelled','paid','waiting_payment','refused','expired'];
    if (!$payment_id || !$status || !in_array($status, $allowed)) {
        http_response_code(400);
        echo json_encode(['erro'=>true,'mensagem'=>'payment_id ou status inválido. use pending|approved|cancelled|paid|waiting_payment|refused|expired']);
        exit;
    }
    $payments = readPayments($DATA_FILE);
    if (!isset($payments[$payment_id])) {
        http_response_code(404);
        echo json_encode(['erro'=>true,'mensagem'=>'payment_id não encontrado']);
        exit;
    }
    $payments[$payment_id]['status'] = $status;
    $payments[$payment_id]['updated_at'] = date(DATE_ATOM);
    writePayments($DATA_FILE, $payments);
    echo json_encode(['payment_id'=>$payment_id,'status'=>$status]);
    exit;
}

// default
http_response_code(400);
echo json_encode(['erro'=>true,'mensagem'=>'Ação inválida']);
exit;

?>
