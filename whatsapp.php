<?php
require_once __DIR__ . '/config.php';

function renderMessageTemplate(PDO $pdo, string $template, array $data): string {
    $replacements = [
        '{name}' => $data['name'] ?? '',
        '{service}' => $data['service'] ?? '',
        '{date}' => $data['date'] ?? '',
        '{time}' => $data['time'] ?? '',
        '{amount}' => $data['amount'] ?? '',
        '{offer}' => $data['offer'] ?? '',
        '{salon}' => getSetting($pdo, 'salon_name'),
        '{salon_phone}' => getSetting($pdo, 'salon_phone'),
        '{salon_address}' => getSetting($pdo, 'salon_address'),
    ];
    return strtr($template, $replacements);
}

function sendWhatsApp(PDO $pdo, string $mobile, string $message): array {
    if (getSetting($pdo, 'whatsapp_enabled') !== '1') {
        return ['ok'=>false, 'status'=>'Disabled', 'response'=>'WhatsApp API is disabled in Settings.'];
    }
    $url = trim(getSetting($pdo, 'whatsapp_api_url'));
    $token = trim(getSetting($pdo, 'whatsapp_api_token'));
    if ($url === '') return ['ok'=>false,'status'=>'Failed','response'=>'WhatsApp API URL is missing.'];

    $phoneField = getSetting($pdo, 'whatsapp_phone_field', 'to');
    $messageField = getSetting($pdo, 'whatsapp_message_field', 'message');
    $payload = [
        $phoneField => normalizeMobile($mobile),
        $messageField => $message,
    ];
    $sender = trim(getSetting($pdo, 'whatsapp_sender_id'));
    if ($sender !== '') $payload['sender_id'] = $sender;

    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headerName = trim(getSetting($pdo, 'whatsapp_token_header', 'Authorization')) ?: 'Authorization';
        $prefix = getSetting($pdo, 'whatsapp_token_prefix', 'Bearer ');
        $headers[] = $headerName . ': ' . $prefix . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return ['ok'=>false,'status'=>'Failed','response'=>$error ?: 'Unknown cURL error'];
    $ok = $http >= 200 && $http < 300;
    return ['ok'=>$ok,'status'=>$ok ? 'Sent' : 'Failed','response'=>'HTTP '.$http.' - '.$response];
}

function logMessage(PDO $pdo, ?int $clientId, ?int $appointmentId, string $mobile, string $type, string $message, array $result): void {
    $stmt = $pdo->prepare('INSERT INTO message_logs(client_id,appointment_id,mobile,message_type,message_text,api_status,api_response) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$clientId,$appointmentId,$mobile,$type,$message,$result['status'],$result['response']]);
}

function sendAppointmentMessage(PDO $pdo, int $appointmentId): array {
    $stmt = $pdo->prepare("SELECT a.*, c.name client_name, c.mobile, s.name service_name FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id WHERE a.id=?");
    $stmt->execute([$appointmentId]);
    $a = $stmt->fetch();
    if (!$a || trim((string)$a['mobile']) === '') return ['ok'=>false,'status'=>'Failed','response'=>'Client mobile number is missing.'];
    $message = renderMessageTemplate($pdo, getSetting($pdo,'appointment_template'), [
        'name'=>$a['client_name'],'service'=>$a['service_name'],'date'=>date('d M Y',strtotime($a['appointment_date'])),'time'=>date('h:i A',strtotime($a['appointment_time'])),'amount'=>'₹'.number_format((float)$a['amount'],0)
    ]);
    $result = sendWhatsApp($pdo, $a['mobile'], $message);
    logMessage($pdo,(int)$a['client_id'],$appointmentId,$a['mobile'],'Appointment',$message,$result);
    if ($result['ok']) $pdo->prepare('UPDATE appointments SET appointment_message_sent=1 WHERE id=?')->execute([$appointmentId]);
    return $result;
}

function sendThankYouMessage(PDO $pdo, int $appointmentId): array {
    $stmt = $pdo->prepare("SELECT a.*, c.name client_name, c.mobile, s.name service_name FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id WHERE a.id=?");
    $stmt->execute([$appointmentId]);
    $a = $stmt->fetch();
    if (!$a || trim((string)$a['mobile']) === '') return ['ok'=>false,'status'=>'Failed','response'=>'Client mobile number is missing.'];
    $message = renderMessageTemplate($pdo, getSetting($pdo,'thank_you_template'), ['name'=>$a['client_name'],'service'=>$a['service_name']]);
    $result = sendWhatsApp($pdo,$a['mobile'],$message);
    logMessage($pdo,(int)$a['client_id'],$appointmentId,$a['mobile'],'Thank You',$message,$result);
    if ($result['ok']) $pdo->prepare('UPDATE appointments SET thank_you_message_sent=1 WHERE id=?')->execute([$appointmentId]);
    return $result;
}
