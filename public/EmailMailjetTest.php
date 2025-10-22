<?php
/**
 * Configuration simple pour envoyer des emails via Mailjet
 */
function mailjet_send($to, $subject, $body) {
    $config = [
        'host' => 'in-v3.mailjet.com',
        'port' => 587,
        'username' => '7250bf4d237dabd14cb30b7d959d15f7',
        'password' => 'f3e68c16c6cb3f2ef4d721df9d0800c2',
        'from' => 'no-reply@wp.scorimmo.com',
        'from_name' => 'Scorimmo transaction'
    ];
    
    return mailjet_smtp_send($to, $subject, $body, $config);
}

function mailjet_smtp_send($to, $subject, $body, $config) {
    $socket = fsockopen($config['host'], $config['port'], $errno, $errstr, 30);
    if (!$socket) return false;
    
    $read = function($s) {
        $r = ''; while ($l = fgets($s, 515)) { $r .= $l; if ($l[3] != '-') break; } return $r;
    };
    
    $read($socket);
    fputs($socket, "EHLO localhost\r\n"); $read($socket);
    fputs($socket, "STARTTLS\r\n"); $read($socket);
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
    fputs($socket, "EHLO localhost\r\n"); $read($socket);
    fputs($socket, "AUTH LOGIN\r\n"); $read($socket);
    fputs($socket, base64_encode($config['username']) . "\r\n"); $read($socket);
    fputs($socket, base64_encode($config['password']) . "\r\n"); $read($socket);
    fputs($socket, "MAIL FROM:<{$config['from']}>\r\n"); $read($socket);
    fputs($socket, "RCPT TO:<$to>\r\n"); $read($socket);
    fputs($socket, "DATA\r\n"); $read($socket);
    
    $msg = "From: {$config['from_name']} <{$config['from']}>\r\n";
    $msg .= "To: $to\r\nSubject: $subject\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n\r\n$body\r\n.\r\n";
    
    fputs($socket, $msg);
    $response = $read($socket);
    fputs($socket, "QUIT\r\n");
    fclose($socket);
    
    return strpos($response, '250') !== false;
}

// Utilisation ultra-simple
mailjet_send(
    'luc@scorimmo.com',
    'Mon sujet',
    '<p>Mon message HTML</p>'
);
?>